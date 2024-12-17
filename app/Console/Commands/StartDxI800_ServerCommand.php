<?php

namespace App\Console\Commands;

use App\Enums\HexCodes;
use Illuminate\Console\Command;

class StartDxI800_ServerCommand extends Command
{

    public const STX = HexCodes::STX->value;
    public const ETX = HexCodes::ETX->value;
    public const EOT = HexCodes::EOT->value;
    public const CR = HexCodes::CR->value;
    public const LF = HexCodes::LF->value;
    public const ACK = HexCodes::ACK->value;
    public const NAK = HexCodes::NAK->value;
    public const ENQ = HexCodes::ENQ->value;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dxi800:start';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts the DxI Analyzer as server';
    protected array $messages = [
        [
            '1H|\^&|||ACCESS^609385|||||LIS||P|1|20240522084809',
            '2Q|1|^1200013589||ALL||||||||O',
            '3L|1|F'
        ],
        [
            '1H|\^&|||ACCESS^609385|||||LIS||P|1|20240522084812',
            '2Q|1|^7080178841||ALL||||||||O',
            '3L|1|F'
        ],
        [
            '1H|\^&|||ACCESS^609385|||||LIS||P|1|20240522084815',
            '2Q|1|^7030133133||ALL||||||||O',
            '3L|1|F'
        ],
        [
            '1H|\^&|||ACCESS^609385|||||LIS||P|1|20240522090500',
            '2P|1|1200013589',
            '3O|1|1200013589|^59^1|^^^Testo^1|||||||||||Serum||||||||||F',
            '4R|1|^^^Testo^1|15.44|nmol/L||N||F||||20240522090519|609385',
            '5L|1|F'
        ],
        [
            '1H|\^&|||ACCESS^609385|||||LIS||P|1|20240522090545',
            '2P|1|7080178841',
            '3O|1|7080178841|^69^4|^^^Testo^1|||||||||||Serum||||||||||F',
            '4R|1|^^^Testo^1|31.91|nmol/L||N||F||||20240522090604|609385',
            '5L|1|F'
        ],
        [
            '1H|\^&|||ACCESS^609385|||||LIS||P|1|20240522090715',
            '2P|1|7030133133',
            '3O|1|7030133133|^69^4|^^^Testo^1|||||||||||Serum||||||||||F',
            '4R|1|^^^Testo^1|0.82|nmol/L||N||F||||20240522090734|609385',
            '5L|1|F'
        ],
        [
            '1H|\^&|||ACCESS^609385|||||LIS||P|1|20240522091633',
            '2P|1|7030133133',
            '3O|1|7030133133|^69^2|^^^SHBG^1|||||||||||Serum||||||||||F',
            '4R|1|^^^SHBG^1|80.06|nmol/L||N||F||||20240522091652|609385',
            '5L|1|F'
        ],
        [
            '1H|\^&|||ACCESS^609385|||||LIS||P|1|20240522094427',
            '2P|1|7030133133',
            '3O|1|7030133133|^69^2|^^^hFSH^1|||||||||||Serum||||||||||F',
            '4R|1|^^^hFSH^1|86.49|IU/L||N||F||||20240522094446|609385',
            '5L|1|F'
        ]
    ];
    private $server_socket;
    private $client_socket;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->server_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $ipAddress = '192.168.0.111';
        $port = 31015;
        socket_bind($this->server_socket, $ipAddress, $port);
        socket_listen($this->server_socket);
        $this->info("Socket server started on {$ipAddress}:{$port}");
        $this->process();
    }


    public function process(): void
    {
        while (true) {
            $this->client_socket = @socket_accept($this->server_socket);
            if ($this->client_socket === false) {
                $this->info("Failed to accept connection");
                continue;
            }

            socket_getpeername($this->client_socket, $ip);
            $this->info("Client IP: $ip");

            while (true) {
                if (socket_get_option($this->client_socket, SOL_SOCKET, SO_ERROR) !== 0) {
                    $this->info("Client disconnected");
                    break;
                }

                foreach ($this->messages as $message_group) {
                    foreach ($message_group as $message) {
                        $this->processAndSendMessage($message);
                        sleep(1);
                    }
                    $this->sendEOT();

                    if ($this->readResponse() === self::ENQ) {
                        echo "ENQ received\n";
                        $this->sendACK();
                        $order_info = $this->readResponse();
                        if ($order_info === self::NAK) {
                            echo "NAK received, order not found\n";
                        }
                    }
                }
                $this->closeConnection();
            }
        }
    }

    private function processAndSendMessage(mixed $message): void
    {
        $message = $message . self::CR . self::ETX;
        $checksum = $this->calculateChecksum($message);
        $message = self::STX . $message . $checksum . self::CR . self::LF;
//        $message = bin2hex($message);
        $this->sendMessage($message);
        $response = $this->readResponse();
        if ($response === self::NAK) {
            echo "NAK received, resending message\n";
            $this->sendMessage($message);
        }
    }

    function calculateChecksum($string): string
    {
        $checksum = 0;
        for ($i = 0; $i < strlen($string); $i++) {
            $checksum += ord($string[$i]);
        }
        $checksum = $checksum & 0xFF; // Get the last 8 bits
        echo "Checksum: $checksum\n";
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
