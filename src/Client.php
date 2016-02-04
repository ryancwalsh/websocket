<?php

namespace vakata\websocket;

class Client
{
    use Base {
        Base::send as protected _send;
    }
    protected $socket = null;
    protected $message = null;
    protected $tick = null;

    /**
     * Create an instance.
     * @method __construct
     * @param  string      $address address to bind to, defaults to `"ws://127.0.0.1:8080"`
     * @param  array       $headers optional array of headers to pass when connecting
     */
    public function __construct($address = 'ws://127.0.0.1:8080', array $headers = [])
    {
        $addr = parse_url($address);
        if ($addr === false || !isset($addr['host']) || !isset($addr['port'])) {
            throw new WebSocketException('Invalid address');
        }

        $this->socket = fsockopen(
            (isset($addr['scheme']) && in_array($addr['scheme'], ['ssl', 'tls', 'wss']) ? 'tls://' : '').$addr['host'],
            $addr['port']
        );
        if ($this->socket === false) {
            throw new WebSocketException('Could not connect');
        }

        $key = $this->generateKey();
        $headers = array_merge(
            $this->normalizeHeaders([
                'Host' => $addr['host'].':'.$addr['port'],
                'Connection' => 'Upgrade',
                'Upgrade' => 'websocket',
                'Sec-WebSocket-Key' => $key,
                'Sec-WebSocket-Version' => '13',
            ]),
            $this->normalizeHeaders($headers)
        );
        $key = $headers['Sec-WebSocket-Key'];
        array_unshift(
            $headers,
            'GET '.(isset($addr['path']) && strlen($addr['path']) ? $addr['path'] : '/').' HTTP/1.1'
        );
        $this->sendClear($this->socket, implode("\r\n", $headers)."\r\n");

        $data = $this->receiveClear($this->socket);
        if (!preg_match('(Sec-WebSocket-Accept:\s*(.*)$)mUi', $data, $matches)) {
            throw new WebSocketException('Bad response');
        }
        if (trim($matches[1]) !== base64_encode(pack('H*', sha1($key.self::$magic)))) {
            throw new WebSocketException('Bad key');
        }
    }
    protected function generateKey()
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"$&/()=[]{}0123456789';
        $key = '';
        $chars_length = strlen($chars);
        for ($i = 0; $i < 16; ++$i) {
            $key .= $chars[mt_rand(0, $chars_length - 1)];
        }

        return base64_encode($key);
    }
    protected function normalizeHeaders($headers)
    {
        $cleaned = [];
        foreach ($headers as $name => $value) {
            if (strncmp($name, 'HTTP_', 5) === 0) {
                $name = substr($name, 5);
            }
            $name = str_replace('_', ' ', strtolower($name));
            $name = str_replace('-', ' ', strtolower($name));
            $name = str_replace(' ', '-', ucwords($name));
            $cleaned[$name] = $value;
        }

        return $cleaned;
    }
    /**
     * Set a callback to execute when a message arrives.
     * 
     * The callable will receive the message string and the server instance.
     * @method onMessage
     * @param  callable  $callback the callback
     * @return self
     */
    public function onMessage(callable $callback)
    {
        $this->message = $callback;

        return $this;
    }
    /**
     * Set a callback to execute every few milliseconds.
     * 
     * The callable will receive the server instance. If it returns boolean `false` the client will stop listening.
     * @method onTick
     * @param  callable  $callback the callback
     * @return self
     */
    public function onTick(callable $callback)
    {
        $this->tick = $callback;

        return $this;
    }
    /**
     * Send a message to the server.
     * @method send
     * @param  string $data   the data to send
     * @param  string $opcode the data opcode, defaults to `"text"`
     * @return bool was the send successful
     */
    public function send($data, $opcode = 'text')
    {
        return $this->_send($this->socket, $data, $opcode, true);
    }
    /**
     * Start listening.
     * @method listen
     */
    public function listen()
    {
        while (true) {
            if (isset($this->tick)) {
                if(call_user_func($this->tick, $this) === false) {
                    break;
                }
            }
            $changed = [$this->socket];
            if (@stream_select($changed, $write = null, $except = null, null) > 0) {
                foreach ($changed as $socket) {
                    $message = $this->receive($socket);
                    if ($message !== false && isset($this->message)) {
                        call_user_func($this->message, $message, $this);
                    }
                }
            }
            usleep(5000);
        }
    }
}
