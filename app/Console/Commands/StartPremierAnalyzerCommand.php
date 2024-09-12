<?php

namespace App\Console\Commands;

use App\Enums\HexCodes;
use Illuminate\Console\Command;

class StartPremierAnalyzerCommand extends Command
{
    const ACK = HexCodes::ACK->value;
    const NAK = HexCodes::NAK->value;
    const ENQ = HexCodes::ENQ->value;
    const STX = HexCodes::STX->value;
    const ETX = HexCodes::ETX->value;
    const EOT = HexCodes::EOT->value;
    const CR = HexCodes::CR->value;
    const LF = HexCodes::LF->value;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'premier:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts the Premier Analyzer';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Premier Analyzer Command');

        $messages = [
            '1H|\^&|||PREMIER^100166||||||ASTM RECVR|||P|E 1394-97|20240909120850',
            '2P|1',
            '3O|1||3588820543|^^^PREMIER HBA1C|R|||||||||||||||||||WL+i^001|F',
            '4R|1|^^^GHb|---|%||||F||||20240906164512||',
            '5R|2|^^^HbA1c|8.5|%||||F||||20240906164512||',
            '6R|3|^^^AG|---|mg/dl||||F||||20240906164512||',
            '7R|4|^^^mMA1c|70|mMol HbA1c/mol Hb||||F||||20240906164512||',
            '8R|5|^^^Code|1 8 12 |||||F||||20090519153950||',
            '9R|6|^^^Data Points|1,1,-1,-3,-2,-1,-
                1,0,1,39,442,3113,9440,13952,12531,8385,4742,2455,1209,632,379,257,194,158,133,
                116,102,92,83,77,71,65,64,64,62,65,111,295,446,427,351,294,261,243,233,222,206,18
                4,157,131,109,87,69,54,44,37,34,33,30,26 |||||F||||20090519153950||',
            '0L|1',
        ];

        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $ipAddress = '127.0.0.1';
        $port = 12000;

        socket_bind($serverSocket, $ipAddress, $port);
        socket_listen($serverSocket);

        $this->info("Socket server started on {$ipAddress}:{$port}");
        $clientSocket = socket_accept($serverSocket);
        socket_getpeername($clientSocket, $ip);
        $this->info("Client IP: $ip");

        while (true) {
            while (socket_get_option($clientSocket, SOL_SOCKET, SO_ERROR) === 0) {
                foreach ($messages as $message) {
                    $message = $this->processMessage($message);

                    socket_write($clientSocket, $message, strlen($message));
                    $this->info("Sent: $message");

                    $response = socket_read($clientSocket, 1024);
                    $this->info("Received: $response");
                    sleep(1);
                }
            }
        }
    }

    private function processMessage(string $message): string
    {
        return $this->prepareMessageString($message);
    }

    private function prepareMessageString(string $message): string
    {
        $message .= self::CR . self::ETX;
        $checksum = $this->getChecksum($message);
        if (strlen($checksum) === 1) {
            $checksum = '0' . $checksum;
        }

        echo "Checksum: $checksum\n";

        $message .= $checksum;
        $message .= self::CR . self::LF;
        $message = self::STX . $message;
        return strtoupper(bin2hex($message));
    }

    private function getChecksum(string $message): string
    {
        $checksum = 0;
        for ($i = 0, $iMax = strlen($message); $i < $iMax; $i++) {
            $checksum += ord($message[$i]);
        }
        $checksum %= 256;
        $checksum &= 0xFF;
        return strtoupper(dechex($checksum));
    }
}
