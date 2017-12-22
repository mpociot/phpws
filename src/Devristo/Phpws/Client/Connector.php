<?php

namespace Devristo\Phpws\Client;

use React\Dns\Resolver\Factory;
use React\Dns\Resolver\Resolver;
use React\EventLoop\LoopInterface;
use React\Promise;
use React\Promise\When;
use React\Socket\ConnectorInterface;
use React\Socket\DnsConnector;
use React\Socket\SecureConnector;
use React\Socket\TcpConnector;
use React\Socket\TimeoutConnector;
use React\Socket\UnixConnector;
use RuntimeException;

class Connector implements ConnectorInterface
{
    protected $options = array();

    public function __construct(LoopInterface $loop, Resolver $resolver, $options = array())
    {
        // apply default options if not explicitly given
        if (is_null($options)) {
            $options = array();
        }
        $options += array(
            'tcp' => true,
            'tls' => true,
            'unix' => true,

            'dns' => true,
            'timeout' => true,
        );

        if ($options['timeout'] === true) {
            $options['timeout'] = (float)ini_get("default_socket_timeout");
        }

        if ($options['tcp'] instanceof ConnectorInterface) {
            $tcp = $options['tcp'];
        } else {
            $tcp = new TcpConnector(
                $loop,
                is_array($options['tcp']) ? $options['tcp'] : array()
            );
        }

        if ($options['dns'] !== false) {
            if ($options['dns'] instanceof Resolver) {
                $resolver = $options['dns'];
            } else {
                $factory = new Factory();
                $resolver = $factory->create(
                    $options['dns'] === true ? '8.8.8.8' : $options['dns'],
                    $loop
                );
            }

            $tcp = new DnsConnector($tcp, $resolver);
        }

        if ($options['tcp'] !== false) {
            $options['tcp'] = $tcp;

            if ($options['timeout'] !== false) {
                $options['tcp'] = new TimeoutConnector(
                    $options['tcp'],
                    $options['timeout'],
                    $loop
                );
            }

            $this->connectors['tcp'] = $options['tcp'];
        }

        if ($options['tls'] !== false) {
            if (!$options['tls'] instanceof ConnectorInterface) {
                $options['tls'] = new SecureConnector(
                    $tcp,
                    $loop,
                    is_array($options['tls']) ? $options['tls'] : array()
                );
            }

            if ($options['timeout'] !== false) {
                $options['tls'] = new TimeoutConnector(
                    $options['tls'],
                    $options['timeout'],
                    $loop
                );
            }

            $this->connectors['tls'] = $options['tls'];
        }

        if ($options['unix'] !== false) {
            if (!$options['unix'] instanceof ConnectorInterface) {
                $options['unix'] = new UnixConnector($loop);
            }
            $this->connectors['unix'] = $options['unix'];
        }

        $options = null === $options ? array() : $options;
        $this->contextOptions = $options;
    }

    public function connect($uri)
    {
        $scheme = 'tcp';
        if (strpos($uri, '://') !== false) {
            $scheme = (string)substr($uri, 0, strpos($uri, '://'));
        }

        if (!isset($this->connectors[$scheme])) {
            return Promise\Reject(new RuntimeException(
                'No connector available for URI scheme "' . $scheme . '"'
            ));
        }

        return $this->connectors[$scheme]->connect($uri);
    }

    public function createSocketForAddress($address, $port, $hostName = null)
    {
        $url = $this->getSocketUrl($address, $port);

        $contextOpts = $this->contextOptions;
        // Fix for SSL in PHP >= 5.6, where peer name must be validated.
        if ($hostName !== null) {
            $contextOpts['ssl']['SNI_enabled'] = true;
            $contextOpts['ssl']['SNI_server_name'] = $hostName;
            $contextOpts['ssl']['peer_name'] = $hostName;
        }

        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;
        $context = stream_context_create($contextOpts);
        $socket = stream_socket_client($url, $errno, $errstr, 0, $flags, $context);

        if (!$socket) {
            return When::reject(new \RuntimeException(
                sprintf("connection to %s:%d failed: %s", $address, $port, $errstr),
                $errno
            ));
        }

        stream_set_blocking($socket, 0);

        // wait for connection

        return $this
            ->waitForStreamOnce($socket)
            ->then(array($this, 'checkConnectedSocket'))
            ->then(array($this, 'handleConnectedSocket'));
    }
}
