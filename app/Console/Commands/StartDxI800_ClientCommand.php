<?php

namespace App\Console\Commands;

use App\Enums\HexCodes;
use Illuminate\Console\Command;

class StartSysmex_CS2500_ClientCommand extends Command
{

    public const STX = HexCodes::STX->value;
    public const ETX = HexCodes::ETX->value;
    public const EOT = HexCodes::EOT->value;
    public const CR = HexCodes::CR->value;
    public const LF = HexCodes::LF->value;

    private $socket;
    private $connection;


    public function __construct()
    {
        parent::__construct();
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sysmex2500:connect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sysmex client sends messages to ths Sysmex server';

    protected array $messages = [
        [
            '1H|\^&|||CS-2500^01-70^21768^^^Rezus^BV981798||||||||E1394-97',
            '2P|1||||^Grazina^Dauksiene',
            '3O|1||000000^02^     6550049260^B^||R||||||N',
            '4R|1|^^^041^PT INN~sec^100.00^A^^^|  10.6|sec||N||||||20240919152929',
            '5R|2|^^^042^PT INN~%^100.00^A^^^| 117.2|%||N||||||20240919152929',
            '6C|1|I|CAL^042^^7^|I',
            '7R|3|^^^043^PT INN cal~INR^100.00^A^^^|  0.93|||N||||||20240919152929',
            '0C|1|I|CAL^043^^7^|I',
            '1R|4|^^^044^DFbg INN~g_L^100.00^A^^^|   5.0|g/L||>||||||20240919152929',
            '2C|1|I|CAL^044^^7^|I',
            '3C|2|I|LOT^040^PT Inn^564636|I',
            '4R|5|^^^051^APTT FS~sec^100.00^A^^^|  25.4|sec||N||||||20240919152929',
            '5C|1|I|LOT^050^APTT FS^562461|I',
            '6L|1|N'
        ],
        [
            '1H|\^&|||CS-2500^01-70^21768^^^Rezus^BV981798||||||||E1394-97',
            '2P|1||||^Alfonsas^Juozaitis',
            '3O|1||000000^01^     6550049257^B^||R||||||N',
            '4R|1|^^^041^PT INN~sec^100.00^A^^^|  11.8|sec||N||||||20240919152849',
            '5R|2|^^^042^PT INN~%^100.00^A^^^|  88.7|%||N||||||20240919152849',
            '6C|1|I|CAL^042^^7^|I',
            '7R|3|^^^043^PT INN cal~INR^100.00^A^^^|  1.05|||N||||||20240919152849',
            '0C|1|I|CAL^043^^7^|I',
            '1R|4|^^^044^DFbg INN~g_L^100.00^A^^^|   5.0|g/L||>||||||20240919152849',
            '2C|1|I|CAL^044^^7^|I',
            '3C|2|I|LOT^040^PT Inn^564636|I',
            '4R|5|^^^051^APTT FS~sec^100.00^A^^^|  26.1|sec||N||||||20240919152849',
            '5C|1|I|LOT^050^APTT FS^562461|I',
            '6L|1|N'
        ],
        [
            '1H|\^&|||CS-1600^00-21^12812^^^CS-1600^BQ203979||||||||E1394-97',
            '2P|1||||^^',
            '3O|1||000003^02^     1200014336^B^^||R||||||N',
            '4R|1|^^^041^PT~sec^100.00^A^^^^|40.6|sec||N||||||20240619145853',
            '5R|2|^^^042^PT~%^100.00^A^^^^|12.8|%||N||||||20240619145853',
            '6R|3|^^^044^PT cal~INR^100.00^A^^^^|4.22|||N||||||20240619145853',
            '7R|4|^^^045^DFbg~gL^100.00^A^^^^|5.0|g/L||>||||||20240619145853',
            '0L|1|N'
        ]
    ];


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
//        $ip = '85.206.48.46'; // rezus public address
//        $ip = '192.168.1.111'; // rezus local address
//        $port = 9999;


        $ip = '192.168.0.111';
        $port = 12000;

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
        foreach ($this->messages as $message_group) {
            foreach ($message_group as $message) {
                $this->processMessage($message);
                sleep(1);
            }
        }
    }

    private function processMessage(mixed $message): void
    {
        $message =  $message . self::ETX;
        $checksum = $this->calculateChecksum($message);
        $message = self::STX . $message . $checksum . self::CR . self::LF;
        $message = bin2hex($message);
        $this->sendMessage($message);
    }

    private function sendMessage(string $message): void
    {
        echo "Sending message: $message\n";
        $bytes_sent = socket_write($this->socket, $message, strlen($message));
        if ($bytes_sent === false) {
            echo "Error sending message\n";
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
        return strtoupper(dechex($checksum));
    }
}
