<?php
/**
 * Copyright (c) 2016. Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 *  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 *  "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 *  LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 *  A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 *  OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 *  SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 *  LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 *  DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 *  THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 *  (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 *  OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 *  This software consists of voluntary contributions made by many individuals
 *  and is licensed under the MIT license.
 */

declare (strict_types=1);

namespace HumusTest\Amqp;

use Humus\Amqp\Channel;
use Humus\Amqp\Envelope;
use Humus\Amqp\Exchange;
use Humus\Amqp\Queue;
use Humus\Amqp\Constants;
use Humus\Amqp\JsonProducer;
use HumusTest\Amqp\Helper\CanCreateExchange;
use HumusTest\Amqp\Helper\CanCreateQueue;
use HumusTest\Amqp\Helper\DeleteOnTearDownTrait;
use PHPUnit_Framework_TestCase as TestCase;

/**
 * Class AbstractJsonProducerTest
 * @package HumusTest\Amqp
 */
abstract class AbstractJsonProducerTest extends TestCase implements
    CanCreateExchange,
    CanCreateQueue
{
    use DeleteOnTearDownTrait;

    /**
     * @var Channel
     */
    protected $channel;

    /**
     * @var Exchange
     */
    protected $exchange;

    /**
     * @var Queue
     */
    protected $queue;

    /**
     * @var JsonProducer
     */
    protected $producer;

    /**
     * @var array
     */
    protected $results = [];

    protected function setUp()
    {
        $connection = $this->createConnection();
        $channel = $connection->newChannel();

        $exchange = $this->createExchange($channel);
        $exchange->setType('topic');
        $exchange->setName('test-exchange');
        $exchange->delete();
        $exchange->declareExchange();

        $queue = $this->createQueue($channel);
        $queue->setName('test-queue');
        $queue->delete();
        $queue->declareQueue();
        $queue->bind('test-exchange', '#');

        $this->channel = $channel;
        $this->exchange = $exchange;
        $this->queue = $queue;

        $this->addToCleanUp($queue);
        $this->addToCleanUp($exchange);
    }

    /**
     * @test
     */
    public function it_produces_and_get_messages_from_queue()
    {
        $producer = new JsonProducer($this->exchange);
        $producer->publish(['foo' => 'bar']);
        $producer->publish(['baz' => 'bam']);

        $msg1 = $this->queue->get(Constants::AMQP_AUTOACK);
        $this->assertEquals('UTF-8', $msg1->getContentEncoding());
        $this->assertEquals('application/json', $msg1->getContentType());
        $body = json_decode($msg1->getBody(), true);

        $this->assertEquals(['foo' => 'bar'], $body);

        $msg2 = $this->queue->get(Constants::AMQP_AUTOACK);
        $this->assertEquals('UTF-8', $msg2->getContentEncoding());
        $this->assertEquals('application/json', $msg2->getContentType());
        $body = json_decode($msg2->getBody(), true);

        $this->assertEquals(['baz' => 'bam'], $body);
    }

    /**
     * @test
     */
    public function it_produces_transactional_and_get_messages_from_queue()
    {
        $producer = new JsonProducer($this->exchange);
        $producer->startTransaction();
        $producer->publish(['foo' => 'bar']);
        $producer->publish(['baz' => 'bam']);
        $producer->commitTransaction();

        $msg1 = $this->queue->get(Constants::AMQP_AUTOACK);
        $msg2 = $this->queue->get(Constants::AMQP_AUTOACK);

        $body = json_decode($msg1->getBody(), true);

        $this->assertEquals(['foo' => 'bar'], $body);

        $body = json_decode($msg2->getBody(), true);

        $this->assertEquals(['baz' => 'bam'], $body);
    }

    /**
     * @test
     */
    public function it_produces_in_confirm_mode()
    {
        $this->exchange->getChannel()->setConfirmCallback(
            function () {
                return false;
            },
            function (int $delivery_tag, bool $multiple, bool $requeue) {
                throw new \Exception('Could not confirm message publishing');
            }
        );

        $producer = new JsonProducer($this->exchange);
        $producer->confirmSelect();

        $connection = $this->createConnection();
        $channel = $connection->newChannel();
        $queue = $this->createQueue($channel);
        $queue->setName('text-queue2');
        $queue->declareQueue();
        $queue->bind('test-exchange');
        $this->addToCleanUp($queue);

        $producer->publish(['foo' => 'bar']);
        $producer->publish(['baz' => 'bam']);

        $this->channel->waitForConfirm();

        $msg1 = $queue->get(Constants::AMQP_NOPARAM);
        $msg2 = $queue->get(Constants::AMQP_AUTOACK);

        $this->assertEquals(['foo' => 'bar'], json_decode($msg1->getBody(), true));
        $this->assertEquals(['baz' => 'bam'], json_decode($msg2->getBody(), true));

        $queue->delete();
    }

    /**
     * @test
     */
    public function it_sends_given_attributes()
    {
        $producer = new JsonProducer($this->exchange, [
            'content_type' => 'application/json',
            'content_encoding' => 'UTF-8',
            'delivery_mode' => 1,
            'type' => 'custom_type',
        ]);
        $producer->publish(['foo' => 'bar']);
        $producer->publish(['baz' => 'bam']);

        $msg1 = $this->queue->get(Constants::AMQP_AUTOACK);
        $this->assertEquals('UTF-8', $msg1->getContentEncoding());
        $this->assertEquals('application/json', $msg1->getContentType());
        $this->assertEquals(1, $msg1->getDeliveryMode());
        $this->assertEquals('custom_type', $msg1->getType());
        $body = json_decode($msg1->getBody(), true);

        $this->assertEquals(['foo' => 'bar'], $body);

        $msg2 = $this->queue->get(Constants::AMQP_AUTOACK);
        $this->assertEquals('UTF-8', $msg2->getContentEncoding());
        $this->assertEquals('application/json', $msg2->getContentType());
        $this->assertEquals(1, $msg2->getDeliveryMode());
        $this->assertEquals('custom_type', $msg2->getType());
        $body = json_decode($msg2->getBody(), true);

        $this->assertEquals(['baz' => 'bam'], $body);
    }

    /**
     * @test
     */
    public function it_uses_confirm_callback()
    {
        $result = [];
        $multipleAcks = false;

        $producer = new JsonProducer($this->exchange);

        $producer->confirmSelect();

        $producer->setConfirmCallback(
            function (int $deliveryTag, bool $multiple) use (&$result, &$multipleAcks) {
                $result[] = 'acked ' . (string) $deliveryTag;
                if ($multiple) {
                    $multipleAcks = $multiple;
                }
                return 3 !== $deliveryTag;
            },
            function (int $deliveryTag, bool $multiple, bool $requeue) use (&$result) {
                $result[] = 'nacked' . (string) $deliveryTag;
                return false;
            }
        );

        $producer->publish(['foo' => 'bar']);
        $producer->publish(['baz' => 'bam']);
        $producer->publish(['bak' => 'bap']);

        $producer->waitForConfirm(1.0);

        if ($multipleAcks && 1 === count($result)) {
            $this->assertEquals('acked 3', $result[0]);
        } elseif ($multipleAcks && 2 === count($result)) {
            $possibilityOne = [
                'acked 1',
                'acked 3'
            ];
            $possibilityTwo = [
                'acked 2',
                'acked 3'
            ];
            $this->assertTrue($result === $possibilityOne || $result === $possibilityTwo);
        } else {
            $this->assertFalse($multipleAcks);
            $this->assertCount(3, $result);
            $this->assertEquals('acked 1', $result[0]);
            $this->assertEquals('acked 2', $result[1]);
            $this->assertEquals('acked 3', $result[2]);
        }
    }

    /**
     * @test
     */
    public function it_uses_confirm_callback_and_fails()
    {
        $result = [];
        $message = '';

        $connection = $this->createConnection();
        $channel = $connection->newChannel();

        $exchange = $this->createExchange($channel);
        $exchange->setType('topic');
        $exchange->setName('invalid-test-exchange');

        $producer = new JsonProducer($exchange);

        $cnt = 2;
        $producer->setConfirmCallback(
            function ($deliveryTag, bool $multiple) use (&$result, &$cnt) {
                $result[] = 'acked ' . (string) $deliveryTag;
                return --$cnt > 0;
            },
            function ($deliveryTag, bool $multiple, bool $requeue) use (&$result) {
                $result = 'nacked' . (string) $deliveryTag;
                return false;
            }
        );

        $producer->confirmSelect();

        $producer->publish(['foo' => 'bar']);

        try {
            $producer->waitForConfirm(2.0);
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }

        $this->assertRegExp('/NOT_FOUND - no exchange \'invalid-test-exchange\' in vhost \'\/humus-amqp-test\'$/', $message);
    }

    /**
     * @test
     */
    public function it_uses_return_callback()
    {
        $result = [];

        $this->queue->unbind($this->exchange->getName());
        $this->queue->delete();

        $producer = new JsonProducer($this->exchange);

        $producer->setReturnCallback(function (
            int $replyCode,
            string $replyText,
            string $exchange,
            string $routingKey,
            Envelope $envelope,
            string $body
        ) use (&$result) {
            $result[] = 'Message returned';
            $result[] = func_get_args();
            return false;
        });

        $producer->publish(['foo' => 'bar'], '', Constants::AMQP_MANDATORY);

        $producer->waitForBasicReturn();

        $this->assertCount(2, $result);
        $this->assertEquals('Message returned', $result[0]);
        $this->assertCount(6, $result[1]);
        $this->assertEquals(312, $result[1][0]);
        $this->assertEquals('NO_ROUTE', $result[1][1]);
        $this->assertEquals('test-exchange', $result[1][2]);
        $this->assertEquals('', $result[1][3]);
        $this->assertInstanceOf(Envelope::class, $result[1][4]);
        $this->assertEquals(['foo' => 'bar'], json_decode($result[1][5], true));
    }
}
