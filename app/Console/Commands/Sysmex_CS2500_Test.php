<?php

namespace App\Console\Commands;

use App\Enums\HexCodes;
use Carbon\Carbon;
use Illuminate\Console\Command;

class Sysmex_CS2500_Test extends Command
{

    public const STX = HexCodes::STX->value;
    public const ETX = HexCodes::ETX->value;
    public const EOT = HexCodes::EOT->value;
    public const CR = HexCodes::CR->value;
    public const LF = HexCodes::LF->value;
    public const ENQ = HexCodes::ENQ->value;
    public const ACK = HexCodes::ACK->value;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sysmex:test';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imitate the Sysmex CS-2500';
    private $socket;
    private $connection;
    private array $messages = [];
    private $barcode = '7010123815';

    public function __construct()
    {
        parent::__construct();
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $connection = $this->connect();
        if (!$connection) {
            echo "Error connecting to the server\n";
            return;
        }

        $this->process();
    }

    public function connect(): bool
    {
        $ip = '192.168.1.247';
        $port = 6670;

        // Attempt to connect to the socket server
        $this->connection = @socket_connect($this->socket, $ip, $port);

        if ($this->connection === false) {
            $errorMessage = socket_strerror(socket_last_error($this->socket));
            echo "Socket connection failed: $errorMessage\n";
            return false;
        }

        echo "Connection established\n";
        return true;
    }

    public function process(): void
    {
        $header = $this->getHeader();
        echo "Header: $header\n";
        $order_request = $this->getOrderRequest();
        echo "Order request: $order_request\n";
        $terminator = $this->getTerminator();
        echo "Terminator: $terminator\n";

        $this->messages = [
            $header,
            $order_request,
            $terminator
        ];

        socket_write($this->socket, self::ENQ, strlen(self::ENQ));
        $response = socket_read($this->socket, 1024);
        if ($response === self::ACK) {
            echo "Received ACK\n";
            foreach ($this->messages as $message) {
                $this->processMessage($message);
                $resp = socket_read($this->socket, 1024);
                if ($resp === self::ACK) {
                    echo "Received ACK\n";
                }
            }
        }
        socket_write($this->socket, self::EOT, strlen(self::EOT));


        $inc = socket_read($this->socket, 1024);
        if ($inc === self::ENQ) {
            echo "Received ENQ\n";
            socket_write($this->socket, self::ACK, strlen(self::ACK));
            echo "Sent ACK\n";
            $inc = socket_read($this->socket, 1024); // header
            echo "Received header: $inc\n";
            echo "Received header hex: " . bin2hex($inc) . "\n";
            socket_write($this->socket, self::ACK, strlen(self::ACK));
            echo "Sent: ACK\n";
            $inc = socket_read($this->socket, 1024); // patient
            echo "Received patient: $inc\n";
            echo "Received patient hex: " . bin2hex($inc) . "\n";
            socket_write($this->socket, self::ACK, strlen(self::ACK));
            echo "Sent: ACK\n";
            $inc = socket_read($this->socket, 1024); // order info
            echo "Received order info: $inc\n";
            echo "Received order info bin: " . bin2hex($inc) . "\n";
            socket_write($this->socket, self::ACK, strlen(self::ACK));
            echo "Sent: ACK\n";
            $inc = socket_read($this->socket, 1024); // terminator
            echo "Received terminator: $inc\n";
            echo "Received terminator hex: " . bin2hex($inc) . "\n";
            socket_write($this->socket, self::ACK, strlen(self::ACK));
            echo "Sent: ACK\n";
            $inc = socket_read($this->socket, 1024);
            if ($inc === self::EOT) {
                echo "Received EOT\n";
            }
        }
    }

    private function processMessage(mixed $message): void
    {
        $message = $message . self::ETX;
        $checksum = $this->calculateChecksum($message);
        $message = self::STX . $message . $checksum . self::CR . self::LF;
        $this->sendMessage($message);
    }

    function calculateChecksum($string): string
    {
        $checksum = 0;
        for ($i = 0; $i < strlen($string); $i++) {
            $checksum += ord($string[$i]);
        }
        $checksum = $checksum & 0xFF; // Get the last 8 bits
        echo "Checksum: " . str_pad(strtoupper(dechex($checksum)), 2, '0', STR_PAD_LEFT) . "\n";
        return str_pad(strtoupper(dechex($checksum)), 2, '0', STR_PAD_LEFT);
    }

    private function sendMessage(string $message): void
    {
        echo "Sending message: $message\n";
        $bytes_sent = socket_write($this->socket, $message, strlen($message));
        if ($bytes_sent === false) {
            echo "Error sending message\n";
        }
    }

    private function getHeader(): string
    {
        return "1H|\^&|||CS-2500^^21768^^^Rezus^BV981798||||||||E1394-97";
    }

    private function getOrderRequest(): string
    {
        $time_date = Carbon::now()->format('YmdHis');
        $this->barcode = str_pad($this->barcode, 15, ' ', STR_PAD_LEFT);
        return "2Q|1|000002^01^" . $this->barcode . "^B||^^^040^PT-INN-cal\^^^050^APTT-FS|0|" . $time_date;
    }

    private function getTerminator(): string
    {
        return '4L|1|N';
    }

}
