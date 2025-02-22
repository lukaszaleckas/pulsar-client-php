<?php
/**
 * Created by PhpStorm.
 * User: Sunny
 * Date: 2022/6/29
 * Time: 10:01 PM
 */

namespace Pulsar;

use Google\CRC32\CRC32;
use Protobuf\AbstractMessage;
use Pulsar\Exception\RuntimeException;
use Pulsar\Proto\BaseCommand;
use Pulsar\Proto\BaseCommand\Type;
use Pulsar\Proto\CommandSend;
use Pulsar\Proto\CommandSendReceipt;
use Pulsar\Proto\KeyValue;
use Pulsar\Proto\MessageMetadata;
use Pulsar\Proto\SingleMessageMetadata;
use Pulsar\Util\Buffer;
use Pulsar\Util\Helper;

/**
 * Class Producer
 *
 * @package Pulsar
 */
class Producer extends Client
{

    /**
     * @var ProducerOptions
     */
    protected $options;


    /**
     * @var array<PartitionProducer>
     */
    protected $producers = [];


    /**
     * @var array<int,array<int,callable>>
     */
    protected $callbacks = [];


    /**
     * @param string $url
     * @param ProducerOptions $options
     * @throws Exception\OptionsException
     */
    public function __construct(string $url, ProducerOptions $options)
    {
        parent::__construct($url, $options);
    }


    /**
     * @return void
     * @throws Exception\IOException
     */
    public function connect()
    {
        parent::initialization();

        // Send CreateProducer Command
        foreach ($this->topicManage->all() as $id => $topic) {
            $io = $this->topicManage->getConnection($topic);
            $this->producers[] = new PartitionProducer($id, $topic, $io, $this->options);
        }
    }




    /**
     * @param string $payload
     * @param array $options
     * @return string
     * @throws RuntimeException
     */
    public function send(string $payload, array $options = []): string
    {
        $producer = $this->getPartitionProducer();
        $messageOptions = new MessageOptions($options);
        $buffer = $this->buildBuffer(
            $producer,
            $payload,
            $messageOptions,
            $messageOptions->getSequenceID()
        );

        /**
         * @var $response Response
         */
        $response = $producer->send($buffer);

        /**
         * @var $receipt CommandSendReceipt
         */
        $receipt = $response->getSubCommand();
        $receipt->getMessageId()->setPartition($producer->getID());
        return Helper::serializeID($receipt->getMessageId());
    }



    /**
     * @param string $payload
     * @param callable $callable
     * @param array $options
     * @return void
     * @throws RuntimeException
     */
    public function sendAsync(string $payload, callable $callable, array $options = [])
    {
        $messageOptions = new MessageOptions($options);
        $sequenceID = $messageOptions->getSequenceID();

        $producer = $this->getPartitionProducer();
        $buffer = $this->buildBuffer($producer, $payload, $messageOptions, $sequenceID);
        $producer->sendAsync($buffer);
        $this->callbacks[ $sequenceID ] = [$producer->getID(), $callable];
    }


    /**
     * @return void
     * @throws Exception\IOException
     * @throws RuntimeException
     */
    public function wait()
    {
        do {

            // It actually takes data from the memory buffer
            $response = $this->eventloop->wait();

            /**
             * @var $receipt CommandSendReceipt
             */
            $receipt = $response->getSubCommand();

            $seqID = $receipt->getSequenceId();

            $callbackData = $this->callbacks[ $seqID ];

            $receipt->getMessageId()->setPartition($callbackData[0]);

            // Execute callback
            call_user_func($callbackData[1], Helper::serializeID($receipt->getMessageId()));

            // Removing
            unset($this->callbacks[ $seqID ]);

        } while (count($this->callbacks));
    }

    /**
     * @param PartitionProducer $producer
     * @param string $payload
     * @param MessageOptions $messageOptions
     * @param int $sequenceID
     * @return Buffer
     * @throws RuntimeException
     */
    protected function buildBuffer(PartitionProducer $producer, string $payload, MessageOptions $messageOptions, int $sequenceID): Buffer
    {
        // [totalSize] [commandSize] [command] [magicNumber] [checksum] [metadataSize] [metadata] [payload]

        $buffer = new Buffer();

        // BaseCommand
        $baseCommand = new BaseCommand();
        $baseCommand->setType(Type::SEND());

        // CommandSend
        $commandSend = new CommandSend();
        $commandSend->setProducerId($producer->getID());
        $commandSend->setSequenceId($sequenceID);
        $commandSend->setNumMessages(1);
        $commandSend->setTxnidLeastBits(null);
        $commandSend->setTxnidMostBits(null);

        $baseCommand->setSend($commandSend);

        // serialize BaseCommand
        $baseCommandBytes = $baseCommand->toStream()->getContents();

        // [commandSize]
        $buffer->writeUint32(strlen($baseCommandBytes));

        // [command]
        $buffer->write($baseCommandBytes);

        // [magicNumber]
        $buffer->writeUint16(0x0e01);

        // only support zlib、none
        $compressionProvider = $this->options->getCompression();

        // metadata
        $msgMetadata = new MessageMetadata();
        $msgMetadata->setProducerName($producer->getName());
        $msgMetadata->setSequenceId(0);
        $msgMetadata->setPublishTime(time() * 1000);
        $msgMetadata->setNumMessagesInBatch(1);
        $msgMetadata->setCompression($compressionProvider->getType());
        $msgMetadata->setPartitionKey($messageOptions->getKey());
        $msgMetadata->setDeliverAtTime($messageOptions->getDeliverAtTime());

        // singleMessageMetadata
        $singleMsgMetadata = new SingleMessageMetadata();
        $singleMsgMetadata->setPayloadSize(strlen($payload));
        $singleMsgMetadata->setEventTime(time() * 1000);
        $singleMsgMetadata->setPartitionKey($messageOptions->getKey());
        $this->appendProperties($singleMsgMetadata, $messageOptions);
        $singleMsgMetadataBytes = $singleMsgMetadata->toStream()->getContents();

        // [metadataSize] [metadata] [payload]
        $packet = '';
        $packet .= Helper::writeUint32(strlen($singleMsgMetadataBytes));    // [metadataSize]
        $packet .= $singleMsgMetadataBytes;                                 // [metadata]
        $packet .= $payload;                                                // [payload]

        $msgMetadata->setUncompressedSize(strlen($packet));
        $msgMetadataBytes = $msgMetadata->toStream()->getContents();

        $msgMetadataSize = strlen($msgMetadataBytes);


        // make checksum bytes
        $compressionPacket = $compressionProvider->encode($packet);
        $checksumBuffer = new Buffer();
        $checksumBuffer->writeUint32($msgMetadataSize);                                           // [metadataSize]
        $checksumBuffer->write($msgMetadataBytes);                                                // [metadata]
        $checksumBuffer->write($compressionPacket);                                               // [payload]

        // [checksum] === [metadataSize] [metadata] [payload]
        $buffer->writeUint32($this->getChecksum($checksumBuffer));

        // [metadataSize]
        $buffer->writeUint32($msgMetadataSize);

        // [metadata]
        $buffer->write($msgMetadataBytes);

        // [payload]
        $buffer->write($compressionPacket);

        // [totalSize]
        $buffer->put(Helper::writeUint32($buffer->length()), 0);

        return $buffer;
    }


    /**
     * @return void
     * @throws Exception\IOException
     * @throws \Exception
     * close producer and socket
     */
    public function close()
    {
        foreach ($this->producers as $producer) {
            $producer->close();
        }

        parent::close();
    }


    /**
     * @return PartitionProducer
     */
    protected function getPartitionProducer(): PartitionProducer
    {
        return $this->producers[ mt_rand(0, count($this->producers) - 1) ];
    }


    /**
     * @param Buffer $buffer
     * @return float|int
     */
    protected function getChecksum(Buffer $buffer)
    {
        $crc = CRC32::create(CRC32::CASTAGNOLI);
        $crc->update($buffer->bytes());
        return hexdec($crc->hash());
    }


    /**
     * @param AbstractMessage $message
     * @param MessageOptions $options
     * @return void
     */
    protected function appendProperties(AbstractMessage &$message, MessageOptions $options)
    {
        foreach ($options->getProperties() as $key => $val) {
            $kv = new KeyValue();
            $kv->setKey($key);
            if (is_array($val)) {
                $val = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $kv->setValue($val);

            /**
             * @var $message MessageMetadata|SingleMessageMetadata
             */
            $message->addProperties($kv);
        }
    }
}