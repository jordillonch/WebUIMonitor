<?php

namespace WebSocket;

/**
 * WebSocket Connection class
 *
 * @author Nico Kaiser <nico@kaiser.me>
 * @author Simon Samtleben <web@lemmingzshadow.net>
 */
class Connection
{
    private $server;
    private $socket;
    private $handshaked = false;
    private $application = null;

	private $socket_id;
	private $connectionId = null;

    public function __construct($server, $socket)
    {
        $this->server = $server;
        $this->socket = $socket;

		// set some client-information:
        $this->socket_id = (int)$this->socket;
		$this->connectionId = md5($this->socket_id . spl_object_hash($this));

        $this->log('Connected');
    }

    private function handshake($data)
    {
        $this->log('Performing handshake');
        $lines = preg_split("/\r\n/", $data);

		// check for valid http-header:
        if(!preg_match('/\AGET (\S+) HTTP\/1.1\z/', $lines[0], $matches))
		{
            $this->log('Invalid request: ' . $lines[0]);
            fclose($this->socket);
            return false;
        }

		// check for valid application:
		$path = $matches[1];
		$this->application = $this->server->getApplication(substr($path, 1));
        if(!$this->application)
		{
            $this->log('Invalid application: ' . $path);
            fclose($this->socket);
            return false;
        }

		// generate headers array:
		$headers = array();
        foreach($lines as $line)
		{
            $line = chop($line);
            if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
			{
                $headers[$matches[1]] = $matches[2];
            }
        }

		// check for supported websocket version:
		if(!isset($headers['Sec-WebSocket-Version']) || $headers['Sec-WebSocket-Version'] < 6)
		{
			$this->log('Unsupported websocket version.');
            fclose($this->socket);
            return false;
		}

		// do handyshake: (hybi-10)
		$secKey = $headers['Sec-WebSocket-Key'];
		$secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
		$response = "HTTP/1.1 101 Switching Protocols\r\n";
		$response.= "Upgrade: websocket\r\n";
		$response.= "Connection: Upgrade\r\n";
		$response.= "Sec-WebSocket-Accept: " . $secAccept . "\r\n\r\n";
        fwrite($this->socket, $response, strlen($response));
		$this->handshaked = true;
		$this->log('Handshake sent');
		$this->application->onConnect($this);

		return true;
    }

    public function onData($data)
    {
        if ($this->handshaked)
		{
            $this->handle($data);
        }
		else
		{
            $this->handshake($data);
        }
    }

    private function handle($data)
    {
		$decodedData = $this->hybi10Decode($data);

    // no data (HACK)
    if(!isset($decodedData['type'])) return true;

		switch($decodedData['type'])
		{
			case 'error':
				return false;
			break;

			case 'ping':
				$this->log('Ping received.');
			break;

			case 'pong':
				$this->log('pong received.');
			break;

			case 'text':
				$this->application->onData($decodedData['payload'], $this);
			break;
		}

		return true;
    }

    public function send($payload, $type = 'text', $masked = false)
    {
		$encodedData = $this->hybi10Encode($payload, $type, $masked);
		if(!@fwrite($this->socket, $encodedData, strlen($encodedData)))
		{
			@fclose($this->socket);
			$this->socket = false;
            return false; // HACK
		}
        return true; // HACK
    }

    public function onDisconnect()
    {
        $this->log('Disconnected', 'info');

        if($this->application)
		{
            $this->application->onDisconnect($this);
        }
//        socket_close($this->socket);
        fclose($this->socket);
    }

    public function log($message, $type = 'info')
    {
        $this->server->log('[client ' . $this->socket_id . '] ' . $message, $type);
    }

	private function hybi10Encode($payload, $type = 'text', $masked = true)
	{
		$frameHead = array();
		$frame = '';
		$payloadLength = strlen($payload);

		switch($type)
		{
			case 'ping':
				$this->log('Sending ping.');
				return chr(137) . chr(4) . 'ping';
			break;

			case 'pong':
				$this->log('Sending pong.');
			break;

			case 'text':
				$mask = array();
				$payloadLength = strlen($payload);

				// generate a random mask:
				for($i = 0; $i < 4; $i++)
				{
					$mask[$i] = chr(rand(0, 255));
				}

				// first bit indicates FIN, Text-Frame:
				$frameHead[0] = 0x81;

				// set payload length (using 1, 3 or 9 bytes)
				if($payloadLength > 65535)
				{
					$payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
			$frameHead[1] = ($masked === true) ? 255 : 127;
					for($i = 0; $i < 8; $i++)
					{
						$frameHead[$i+2] = bindec($payloadLengthBin[$i]);
					}
					// most significant bit MUST be 0 (return false if to much data)
					if($frameHead[2] > 127)
					{
						return false;
					}
				}
				elseif($payloadLength > 125)
				{

					$payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
			$frameHead[1] = ($masked === true) ? 254 : 126;
					$frameHead[2] = bindec($payloadLengthBin[0]);
					$frameHead[3] = bindec($payloadLengthBin[1]);
				}
				else
				{
			$frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
				}

				// convert frame-head to string:
				foreach(array_keys($frameHead) as $i)
				{
					$frameHead[$i] = chr($frameHead[$i]);
				}
		if($masked === true)
		{
			// generate a random mask:
			$mask = array();
			for($i = 0; $i < 4; $i++)
			{
				$mask[$i] = chr(rand(0, 255));
			}

			$frameHead = array_merge($frameHead, $mask);
		}
		$frame = implode('', $frameHead);

				// mask payload data and append to frame:
				$framePayload = array();
				for($i = 0; $i < $payloadLength; $i++)
				{
			$frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
				}
			break;

			case 'close':
			break;
		}


		return $frame;
	}

	function hybi10Decode($data)
	{
		$payloadLength = '';
		$mask = '';
		$unmaskedPayload = '';
		$decodedData = array();

		// estimate frame type:
		$firstByteBinary = sprintf('%08b', ord($data[0]));
		$secondByteBinary = sprintf('%08b', ord($data[1]));
		$opcode = bindec(substr($firstByteBinary, 4, 4));
		$isMasked = ($secondByteBinary[0] == '1') ? true : false;
		$payloadLength = ($isMasked === true) ? ord($data[1]) & 127 : ord($data[1]);

		// close connection if unmasked frame is received:
		if($isMasked === false)
		{
			$this->close(1002);
		}

		switch($opcode)
		{
			// text frame:
			case 1:
				$decodedData['type'] = 'text';

				if($payloadLength === 126)
				{
				   $mask = substr($data, 4, 4);
				   $payloadOffset = 8;
		   $dataLength = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3]))) + $payloadOffset;
				}
				elseif($payloadLength === 127)
				{
					$mask = substr($data, 10, 4);
					$payloadOffset = 14;
			$tmp = '';
			for($i = 0; $i < 8; $i++)
			{
				$tmp .= sprintf('%08b', ord($data[$i+2]));
				}
			$dataLength = bindec($tmp) + $payloadOffset;
			unset($tmp);
		}
				else
				{
					$mask = substr($data, 2, 4);
					$payloadOffset = 6;
			$dataLength = $payloadLength + $payloadOffset;
				}

				$dataLength = strlen($data);
				for($i = $payloadOffset; $i < $dataLength; $i++)
				{
					$j = $i - $payloadOffset;
					$unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
				}

				$decodedData['payload'] = $unmaskedPayload;
			break;

			// connection close frame:
			case 8:
				$decodedData['type'] = 'close';
			break;

			// ping frame:
			case 9:
				$decodedData['type'] = 'ping';
			break;

			// pong frame:
			case 10:
				$decodedData['type'] = 'pong';
			break;

			default:
				// @TODO: Close connection on unknown opcode.
			break;
		}

		return $decodedData;
	}

	public function getSocketId()
	{
		return $this->socket_id;
	}

	public function getClientId()
	{
		return $this->connectionId;
	}
}