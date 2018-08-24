<?php
/**
  *  packet types
  *  0 open
  *  Sent from the server when a new transport is opened (recheck)

  *  1 close
  *  Request the close of this transport but does not shutdown the connection itself.

  *  2 ping
  *  Sent by the client. Server should answer with a pong packet containing the same data

  *  3 pong
  *  Sent by the server to respond to ping packets.

  *  4 message
  *  actual message, client and server should call their callbacks with the data.

  *  5 upgrade
  *  Before engine.io switches a transport, it tests, if server and client can communicate over this transport. If this *    test succeed, the client sends an upgrade packets which requests the server to flush its cache on the old transport *   and switch to the new transport.

  *  6 noop
  *  A noop packet. Used primarily to force a poll cycle when an incoming websocket connection is received.


  *  packet data types
  *  Packet#CONNECT (0)
  *  Packet#DISCONNECT (1)
  *  Packet#EVENT (2)
  *  Packet#ACK (3)
  *  Packet#ERROR (4)
  *  Packet#BINARY_EVENT (5)
  *  Packet#BINARY_ACK (6)

  *  basic format    => $socket->emit("message", "hello world");
  *                  => sprintf('%d%d%s', $packetType, $packetDataType, json_encode([$event, $data])) 
  *                  => 42["message", "hello world"]
  */
class SocketIOParser
{
    private static $instance;
    public $id;
    protected $server;
    protected $events = [];

    private $ackId;


    private function __construct()
    {
    }

    public static function getInstance()
    {
        return self::$instance ?: (self::$instance = new self);
    }


    /**
     * register socketio event
     * @param $event
     * @param Closure $callback
     */
    public function on($event, Closure $callback)
    {
        if (is_string($event)) {
            $this->events[$event] = $callback;
        }
    }

    /**
     * listen server event
     * @param $server
     */
    public function bindEngine($server)
    {
        $server->on('Open', [$this, 'onOpen']);
        $server->on('Message', [$this, 'onMessage']);
        $server->on('Close', [$this, 'onClose']);
    }


    public function onOpen($server, $request)
    {
        $this->server = $server;
        $this->id = $request->fd;
        $data = [
            'sid' => 'abcdefg',         //whatever
            'upgrades' => ['websocket'],
            'pingInterval' => 25000,
            'pingTimeout' => 60000,
        ];
        $server->push($request->fd, '0'.json_encode($data)); //socket is open
        $server->push($request->fd, '40');  //client is connected
        if (isset($this->events['connection'])) {
            $this->events['connection']($this);
        }
    }

    public function onClose($server, $fd)
    {
        $this->server = $server;
        $this->id = $fd;
        if (isset($this->events['disconnect'])) {
            $this->events['disconnect']($this);
        }
    }


    public function onMessage($server, $frame)
    {
        $this->server = $server;
        $this->id = $frame->fd;

        if ($index = strpos($frame->data, '[')) {
            $code = substr($frame->data, 0, $index);
            $data = json_decode(substr($frame->data, $index), true);
        } else {
            $code = $frame->data;
            $data = '';
        }

        switch (mb_strlen($code)) {
            case 0:break;
            case 1:
                switch ($code) {
                    case '2':   //client ping
                        $server->push($frame->fd, '3'); //sever pong
                        break;
                }
                break;
            case 2:
                switch ($code) {
                    case '41':   //client disconnect
                        $this->close();
                        break;
                    case '42':   //client message
                        if (isset($this->events[$data[0]])) {
                            $this->events[$data[0]]($this, $data[1]);
                        }
                        break;
                }
                break;
            default:
                switch ($code[0]) {
                    case '4':   //client message
                        switch ($code[1]) {
                            case '2':   //client message with ack
                                $this->ackId = substr($code, 2);
                                $this->events[$data[0]]($this, $data[1], [$this, 'ack']);
                                break;

                            case '3':   //client reply to message with ack
                                break;
                        }
                        break;
                }
                break;
        }
    }


    public function emit($event, $data)
    {
        return $this->server->push($this->id, '42'.json_encode([$event, $data]));
    }

    public function emitTo($event, $data, $clientId)
    {
        return $this->server->push($clientId, '42'.json_encode([$event, $data]));
    }

    public function disconnect()
    {
        $this->server->push($this->id, '41'); //notice client is about to disconnect
        $this->close();
    }

    public function ack($data)
    {
        $this->server->push($this->id, '43'.$this->ackId.json_encode($data));
    }

    public function close()
    {
        $sever = $this->server;
        $id = $this->id;
        //close delay 2s
        $this->server->after(2000, function () use ($sever, $id) {
            $sever->close($id);
        });
    }
}

