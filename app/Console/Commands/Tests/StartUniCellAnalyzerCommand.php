<?php

namespace App\Console\Commands;

use App\Enums\HexCodes;
use Exception;
use Illuminate\Console\Command;
use Socket;

class StartUniCellAnalyzerCommand extends Command
{
    const ACK = HexCodes::ACK->value;
    const NAK = HexCodes::NAK->value;
    const ENQ = HexCodes::ENQ->value;
    const STX = HexCodes::STX->value;
    const ETX = HexCodes::ETX->value;
    const EOT = HexCodes::EOT->value;
    const CR = HexCodes::CR->value;
    const LF = HexCodes::LF->value;

    protected $signature = 'unicell:start';
    protected $description = 'Starts the test client';
    private bool $connection = false;
    private Socket $clientSocket;
    private array $messages_result;
    private array $messages_request;
    private $serverSocket;

    public function handle(): void
    {
        $this->info('UniCell Analyzer Command');
        $this->startServer();
        $this->process();
    }

    private function process()
    {
        while (true) {
            $this->createClient();

            while ($this->connection) {
                $error = socket_get_option($this->clientSocket, SOL_SOCKET, SO_ERROR);
                if ($error !== 0) {
                    $this->info("Client disconnected. Waiting for new connection...");
                    socket_close($this->clientSocket);
                    $this->connection = false;
                    break;
                }
                $this->sendAndReceiveMessages();
            }
        }
    }

    private function prepareMessage(string $message): string
    {
        $message = $message . self::CR . self::ETX;
        $checksum = $this->getChecksum($message);
        if (strlen($checksum) === 1) {
            $checksum = '0' . $checksum;
        }
        $this->info("Checksum: $checksum");

        $message .= $checksum;
        $message .= self::CR . self::LF;
        $message = self::STX . $message;
        return strtoupper(bin2hex($message));
    }

    private function getChecksum(string $message): string
    {
        $checksum = 0;
        for ($i = 0; $i < strlen($message); $i++) {
            $checksum += ord($message[$i]);
        }
        $checksum %= 256;
        $checksum = $checksum & 0xFF;
        return strtoupper(dechex($checksum));
    }

    /**
     * @throws Exception
     */
    private function sendMessage(string $message): void
    {
        $timeoutSec = 15;
        $timeoutUsec = 0;

        // Attempt to write to the socket
        try {
            $bytesWritten = @socket_write($this->clientSocket, $message, strlen($message));
            if ($bytesWritten === false) {
                $errorCode = socket_last_error($this->clientSocket);
                $errorMessage = socket_strerror($errorCode);
                $this->info("Socket write error [$errorCode]: $errorMessage");
                $this->connection = false;
                return;
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        $this->info("Sent: $message");

        $read = [socket_export_stream($this->clientSocket)];
        $write = null;
        $except = null;

        // Ensure the read array is not empty
        if (!empty($read)) {
            $ready = stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);

            if ($ready > 0) {
                $response = socket_read($this->clientSocket, 1024);
                $this->info("Received: " . bin2hex($response) . "\n");
            } else {
                $this->info("No response within 15 seconds. Resending message.");
                $this->sendMessage($message);
            }
        } else {
            $this->info("No valid streams to select.");
        }
    }

    private function handleOrderInformation(): void
    {
        // Set a 15-second timeout for receiving data
        socket_set_option($this->clientSocket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 15, 'usec' => 0]);
        try {
            do {
                $inc = socket_read($this->clientSocket, 1024);
                if ($inc) {
                    switch ($inc) {
                        case self::ENQ:
                            $this->handleEnq();
                            break;
                        case self::EOT:
                            $this->handleEot();
                            break;
                        default:
                            $header = $this->getDataMessageHeader($inc);
                            if ($header) {
                                switch ($header) {
                                    case 'H':
                                        $this->handleHeader($inc);
                                        break;
                                    case 'P':
                                        $this->handlePatient($inc);
                                        break;
                                    case 'O':
                                        $this->handleOrder($inc);
                                        break;
                                    case 'L':
                                        $this->handleTerminator($inc);
                                        break;
                                }
                            } else {
                                try {
                                    socket_write($this->clientSocket, self::NAK, strlen(self::NAK));
                                } catch (Exception $e) {
                                    $this->info("NAK error: " . $e->getMessage());
                                    $this->connection = false;
                                }
                            }
                    }
                }

            } while ($inc != self::EOT);
        } catch (Exception $e) {
            sleep(5);
            $this->process();
        }
    }

    private function handleEnq(): void
    {
        $this->info("ENQ received");
        socket_write($this->clientSocket, self::ACK, strlen(self::ACK));
        $this->info("ACK sent");
    }

    private function handleEot(): void
    {
        $this->info("EOT received");
    }

    private function getDataMessageHeader($inc): false|string
    {
        echo "Data message received: $inc\n";
        $inc = $this->processMessage($inc);
//        echo "Processed message: $inc\n";
        if (!$inc) {
            return false;
        }

        // get first segment of the message
        $first_segment = explode('|', $inc)[0];
        // remove all characters that are not letters from the first segment
        return preg_replace('/[^a-zA-Z]/', '', $first_segment);
    }

    private function processMessage(false|string $inc): false|string
    {
        $checksum = $this->getChecksumFromString($inc);
        $prepared_inc = $this->prepareMessageForChecksumCalculation($inc);
        $checksum_ok = $this->checkChecksum($prepared_inc, $checksum);

        if (!$checksum_ok) {
            return false;
        }

        return $this->cleanMessage($inc);
    }

    private function getChecksumFromString(string $inc): string
    {
        $inc = substr($inc, 0, -4);
        $checksum = substr($inc, -4);
        return strtoupper(hex2bin($checksum));
    }

    private function prepareMessageForChecksumCalculation(string $inc): string
    {
        $inc = substr($inc, 2);
        $inc = substr($inc, 0, -4);
        return substr($inc, 0, -4);
    }

    private function checkChecksum(string $inc, string $checksum): bool
    {
        if ($checksum[0] == '0') {
            $checksum = substr($checksum, 1);
        }
        $calculatedChecksum = $this->calculateChecksum($inc);
        if ($calculatedChecksum != $checksum) {
            return false;
        }
        echo "Checksum OK\n";
        return true;
    }

    private function calculateChecksum(string $inc): string
    {
        $hex_array = str_split($inc, 2);
        $checksum = 0;
        foreach ($hex_array as $hex) {
            $checksum += hexdec($hex);
        }
        $checksum = $checksum & 0xFF;
        return strtoupper(dechex($checksum));
    }

    private function cleanMessage(string $inc): string
    {
        $inc = substr($inc, 2);
        $inc = substr($inc, 0, -4);
        $inc = substr($inc, 0, -8);
        return hex2bin($inc);
    }

    private function handleHeader(string $inc): void
    {
        $inc = $this->cleanMessage($inc);
        echo "Header received: $inc\n";
        $this->sendACK();
    }

    private function sendACK(): void
    {
        try {
            socket_write($this->clientSocket, self::ACK, strlen(self::ACK));
            echo "ACK sent\n";
        } catch (Exception $e) {
            echo "ACK error: " . $e->getMessage() . "\n";
            $this->reconnect();
        }
    }

    private function reconnect(): void
    {
        $this->info("Reconnecting...");
        socket_close($this->clientSocket);
        $this->connection = false;
    }

    private function handlePatient(string $inc): void
    {
        $inc = $this->cleanMessage($inc);
        echo "Patient data received: $inc\n";
        $this->sendACK();
    }

    private function handleOrder(string $inc): void
    {
        $inc = $this->cleanMessage($inc);
        echo "Order data received: $inc\n";
        $this->sendACK();
    }

    private function handleTerminator(string $inc): void
    {
        $inc = $this->cleanMessage($inc);
        echo "Terminator received: $inc\n";
        $this->sendACK();
    }

    private function startServer(): void
    {
        $this->messages_result = [
            '1H|\^&|||ACCESS^609385|||||LIS||P|1|20240522080218',
            '2P|1|20240522080218',
            '3O|1|LYPHOCHEK1|^651^1|^^^FRT4^1|||||||||||Serum||||||||||F',
            '4R|1|^^^FRT4^1|5.0|mmol/L|3.0-6.0|H|||N|F|||20240522080218',
            '5L|1|F',
        ];

        $this->messages_request = [
            '1H|\^&|||ACCESS^500001|||||LIS||P|1|20111231235959',
            '2Q|1|^3588820543||ALL||||||||O',
            '3L|1|F',
        ];

        $this->serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $ipAddress = '127.0.0.1';
        $port = 12000;

        socket_bind($this->serverSocket, $ipAddress, $port);
        socket_listen($this->serverSocket);

        $this->info("Socket server started on {$ipAddress}:{$port}");
    }

    private function createClient(): void
    {
        $this->clientSocket = @socket_accept($this->serverSocket);
        if ($this->clientSocket === false) {
            $this->info("Waiting for client connection...");
            sleep(1);
            $this->createClient();
        } else {
            $this->connection = true;
        }

        socket_getpeername($this->clientSocket, $ip);
        $this->info("Client IP: $ip");
    }

    /**
     * @throws Exception
     */
    private function sendAndReceiveMessages(): void
    {
        socket_write($this->clientSocket, self::ENQ, strlen(self::ENQ));
        $this->info("ENQ sent");
        $inc = socket_read($this->clientSocket, 1024);
        if ($inc) {
            switch ($inc) {
                case self::ACK:
                    $this->info("ACK received");
                    $result = rand(0, 1);
                    if ($result) {
                        foreach ($this->messages_result as $message) {
                            if (!$this->connection) {
                                break;
                            }
                            $message = $this->prepareMessage($message);
                            $this->sendMessage($message);
                            sleep(1);
                        }
                        socket_write($this->clientSocket, self::EOT, strlen(self::EOT));
                    } else {
                        foreach ($this->messages_request as $message) {
                            if (!$this->connection) {
                                break;
                            }
                            $this->info("Sending request message: $message");
                            $message = $this->prepareMessage($message);
                            $this->sendMessage($message);
                            sleep(1);
                        }
                        socket_write($this->clientSocket, self::EOT, strlen(self::EOT));
                        echo "EOT sent\n";
                        $this->handleOrderInformation();
                    }
                    break;
                case self::NAK:
                    $this->info("NAK received");
                    break;
                default:
                    $this->info("Invalid response: $inc");
                    break;
            }
        }

    }
}



