<?php

namespace App\Console\Commands;

use App\Enums\HexCodes;
use Illuminate\Console\Command;

class StartAlegriaServerCommand extends Command
{

    private const SOCKET_TIMEOUT = ['sec' => 2, 'usec' => 0];

    public const STX = HexCodes::STX->value;
    public const ETX = HexCodes::ETX->value;
    public const EOT = HexCodes::EOT->value;
    public const CR = HexCodes::CR->value;
    public const LF = HexCodes::LF->value;
    public const ACK = HexCodes::ACK->value;
    public const NAK = HexCodes::NAK->value;
    public const ENQ = HexCodes::ENQ->value;
    public const ETB = HexCodes::ETB->value;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alegria:start';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts the Alegria Analyzer as server';
    protected array $messages = [
        [
            '1H|\^&|||112133||||||||1|20250206145432',
            '2P|||7080211173||||||||||||||||||||||',
            '3O||7080211173|9151604424380162|^^^Mpneu-G|||||||||||||||||||||F',
            '4R|1|^^^Mpneu-G|2.7^U/ml^Neg|||||F||JUSTINA1||20250206140621',
            '5C|1|I|QC Approved|I',
            '6L|1|N'
        ],
        [
            '7H|\^&|||112133||||||||1|20250206145437',
            '0P|||7080211173||||||||||||||||||||||',
            '1O||7080211173|9155603424382773|^^^Mpneu-MX|||||||||||||||||||||F',
            '2R|1|^^^Mpneu-MX|8.0^U/ml^Neg|||||F||JUSTINA1||20250206140621',
            '3C|1|I|QC Approved|I',
            '4L|1|N'
        ],
    ];
    private $server_socket;
    private $client_socket;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->server_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $ipAddress = '192.168.0.10';
        $port = 31000;
        socket_bind($this->server_socket, $ipAddress, $port);
        socket_listen($this->server_socket);
        $this->info("Socket server started on {$ipAddress}:{$port}");
        $this->process();
    }


    public function process(): void
    {
        $this->client_socket = @socket_accept($this->server_socket);
//        if ($this->client_socket === false) {
//            $this->info("Failed to accept connection");
//            continue;
//        }
        socket_set_option($this->client_socket, SOL_SOCKET, SO_RCVTIMEO, self::SOCKET_TIMEOUT);

        socket_getpeername($this->client_socket, $ip);
        $this->info("Client IP: $ip");

        while (true) {
            if (socket_get_option($this->client_socket, SOL_SOCKET, SO_ERROR) !== 0) {
                $this->info("Client disconnected");
                break;
            }

            while (true) {
                $response = @socket_read($this->client_socket, 1024);

                if ($response === false || $response === '') {
                    // No message received, send ENQ
                    socket_write($this->client_socket, self::ENQ);
                    echo "ENQ sent\n";
                    usleep(300000); // Sleep for 300 milliseconds to avoid busy-waiting
                    continue;
                }

                if ($response === self::ACK) {
                    break; // Exit loop if ACK is received
                }
            }

            foreach ($this->messages as $message_group) {
                foreach ($message_group as $message) {
                    $this->processAndSendMessage($message);
                }
            }

            $this->sendEOT();
//            $this->closeConnection();
        }
    }

    private function processAndSendMessage(mixed $message): void
    {
        if (str_contains($message, 'L|1|N')) {
            $message .= self::CR . self::ETB;
        } else {
            $message .= self::CR . self::ETX;
        }

        $checksum = $this->calculateChecksum($message);
        $message = self::STX . $message . $checksum . self::CR . self::LF;
        $this->sendMessage($message);
        $response = $this->readResponse();
        if ($response === self::NAK) {
            echo "NAK received, resending message\n";
            $this->sendMessage($message);
        }
    }

    public function calculateChecksum($string): string
    {
        $checksum = 0;
        for ($i = 0, $iMax = strlen($string); $i < $iMax; $i++) {
            $checksum += ord($string[$i]);
        }
        $checksum &= 0xFF; // Get the last 8 bits
        echo "Checksum: " . str_pad(strtoupper(dechex($checksum)), 2, '0', STR_PAD_LEFT) . "\n";
        return str_pad(strtoupper(dechex($checksum)), 2, '0', STR_PAD_LEFT);
    }

    private function sendMessage(string $message): void
    {
        echo "Sending message: $message\n";
        echo "Sending message in hex: " . bin2hex($message) . "\n";
        $bytes_sent = socket_write($this->client_socket, $message, strlen($message));
        if ($bytes_sent === false) {
            echo "Error sending message\n";
        }
    }

    private function readResponse(): false|string
    {
        $response = @socket_read($this->client_socket, 1024);
        if ($response === false) {
            echo "Error reading response\n";
            return false;
        }

        echo match ($response) {
            self::ACK => "ACK received\n",
            self::NAK => "NAK received\n",
            self::EOT => "EOT received\n",
            self::ENQ => "ENQ received\n",
            default => "Unknown response: $response\n",
        };
        return $response;
    }

    private function sendEOT(): void
    {
        $this->sendMessage(self::EOT);
        echo "EOT sent\n";
    }

    private function sendACK(): void
    {
        $this->sendMessage(self::ACK);
        echo "ACK sent\n";
    }

    private function closeConnection(): void
    {
        socket_close($this->client_socket);
        echo "Connection closed\n";
    }

}
