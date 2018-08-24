<?php


$swoole = new \swoole_websocket_server('0.0.0.0', 3001);


$socketioHandler = SocketIOParser::getInstance();

//register socketio events
$socketioHandler->on('connection', function ($socket) {
    echo 'connection:'.$socket->id.PHP_EOL;
});

$socketioHandler->on('disconnect', function ($socket) {
    echo 'disconnected:'.$socket->id.PHP_EOL;
});

$socketioHandler->on('message', function ($socket, $data) {
    echo 'message:'.PHP_EOL; print_r($data);
    $socket->emit('message', ['hello' => 'message received']);
    $socket->disconnect();
});

$socketioHandler->on('message_with_callback', function ($socket, $data, $ack = '') {
    echo 'message_with_callback:'.PHP_EOL; print_r($data);
    $ack && $ack('hello there');
});


//start websocket server
$socketioHandler->bindEngine($swoole);
$swoole->start();
