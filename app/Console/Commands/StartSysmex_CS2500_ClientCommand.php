<?php

namespace App\Console\Commands;

use App\Enums\HexCodes;
use App\Libraries\Analyzers\Default\DefaultClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class StartDefaultClientCommand extends Command
{
    public const ACK = HexCodes::ACK->value;
    public const NAK = HexCodes::NAK->value;
    public const ENQ = HexCodes::ENQ->value;
    public const STX = HexCodes::STX->value;
    public const ETX = HexCodes::ETX->value;
    public const EOT = HexCodes::EOT->value;
    public const CR = HexCodes::CR->value;
    public const LF = HexCodes::LF->value;

    private $socket;
    private $connection;
    private bool $receiving = true;
    private bool $order_requested = false;
    private bool $order_found = false;
    private string $order_record = '';
    private string $header = '';
    private string $patient = '';
    private string $terminator = '';
    private string $barcode = '';

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
    protected $signature = 'default:connect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Default client sends messages to ths default server';

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

        ]
    ];


    /**
     * Execute the console command.
     */
    public function handle()
    {
        $connection = $this->connect();
        if (!$connection) {
            return;
        }

        $this->process();
    }

    public function connect(): bool
    {
//        $ip = '85.206.48.46';
//    $ip = '192.168.1.111';
//        $port = 9999;

//    $ip = '127.0.0.1';
//    $port = 12000;

        $ip = '192.168.0.111';
        $port = 9999;

        // Attempt to connect to the socket server
        $this->connection = @socket_connect($this->socket, $ip, $port);

        if ($this->connection === false) {
            $errorMessage = socket_strerror(socket_last_error($this->socket));
            echo "Socket connection failed: $errorMessage\n";
            Log::channel('default_client_log')->error(now() . " -> Socket connection failed. Error: " . ": $errorMessage");
            return false;
        }

        echo "Connection established\n";
        Log::channel('default_client_log')->debug(now() . ' -> Connection to analyzer established');
        return true;
    }
}
