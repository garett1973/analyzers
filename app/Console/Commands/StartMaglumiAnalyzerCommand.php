<?php

namespace App\Console\Commands;

use App\Enums\HexCodes;
use Illuminate\Console\Command;

class StartMaglumiServer extends Command
{
    const ACK = HexCodes::ACK->value;
    const NAK = HexCodes::NAK->value;
    const ENQ = HexCodes::ENQ->value;
    const STX = HexCodes::STX->value;
    const ETX = HexCodes::ETX->value;
    const EOT = HexCodes::EOT->value;
    const CR = HexCodes::CR->value;
    const LF = HexCodes::LF->value;

    protected $signature = 'socket:start';
    protected $description = 'Start the socket server';

    private $results = [
        'R|1|^3218577797|^^^25-OH VD II|5.7|%|4.0-6.0|N||F|||20240507|', // Vitamino D koncentracijos nustatymas (25-hidroksivitaminas D)
        'R|1|^3218577797|^^^CA125|5.7|%|4.0-6.0|N||F|||20240507|', // Kiaušidžių vėžio žymuo CA 125
        'R|1|^3218577797|^^^TOXO-IgG|5.7|%|4.0-6.0|N||F|||20240507|', // Toxoplasma gondii IgG nustatymas imunofermentiniu metodu
        'R|1|^3218577797|^^^TOXO_IgM|5.7|%|4.0-6.0|N||F|||20240507|', // Toxoplasma gondii IgM nustatymas imunofermentiniu metodu
        'R|1|^3218577797|^^^CA72-4|5.7|%|4.0-6.0|N||F|||20240507|', // Skrandžio vėžio žymens Ca 72-4 nustatymas
        'R|1|^3218577797|^^^PCT|5.7|%|4.0-6.0|N||F|||20240507|', // Prokalcitoninas (PCT)
        'R|1|^3218577797|^^^BGP|5.7|%|4.0-6.0|N||F|||20240507|', // Osteokalcino nustatymas imunofermentiniu metodu
        'R|1|^3218577797|^^^NT-proBNP|5.7|%|4.0-6.0|N||F|||20240507|', // NT pro BNP (širdies nepakankamumo žymuo) nustatymas
        'R|1|^3218577797|^^^IgE|5.7|%|4.0-6.0|N||F|||20240507|', // Imunoglobulino E koncentracijos nustatymas
        'R|1|^3218577797|^^^Anti-HCV II|5.7|%|4.0-6.0|N||F|||20240507|', // Hepatito C viruso (HCV) kiekybinis antikūnų nustatymas
        'R|1|^3218577797|^^^Ferritin|5.7|%|4.0-6.0|N||F|||20240507|', // Feritino koncentracijos nustatymas
        'R|1|^3218577797|^^^HE4|5.7|%|4.0-6.0|N||F|||20240507|', // Epitelinis kiaušidžių vėžio žymuo HE4
        'R|1|^3218577797|^^^CMV_IgM|5.7|%|4.0-6.0|N||F|||20240507|', // Citomegalo viruso (CMV) IgM nustatymas
        'R|1|^3218577797|^^^CMV_IgG|5.7|%|4.0-6.0|N||F|||20240507|', // Citomegalo viruso (CMV) IgG nustatymas
    ];

    private bool $result_sent = false;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $ipAddress = '127.0.0.1';
        $port = 12000;

        socket_bind($serverSocket, $ipAddress, $port);
        socket_listen($serverSocket);

        $this->info("Socket server started on {$ipAddress}:{$port}");

        $idle = true;
        $counter = 5;
        $inc = '';
        $clientSocket = socket_accept($serverSocket);
//        $clientStream = socket_import_stream($clientSocket);
        $this->info("Client connected");
        //get client ip address
        socket_getpeername($clientSocket, $ip);
        $this->info("Client IP: $ip");

        socket_set_option($clientSocket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 15, 'usec' => 0]);

        while (true) {
            while (socket_get_option($clientSocket, SOL_SOCKET, SO_ERROR) === 0) {
                while ($idle && $counter > 0) {
                    $this->sendENQ($clientSocket);
                    $inc = socket_read($clientSocket, 1024);
                    if ($inc) {
                        echo "Received: $inc\n";
                    } else {
                        $timeout = $this->checkIfTimeout($clientSocket);
                        if ($timeout) {
                            $this->info("Timeout occurred");
                            $counter = 5;
                            continue;
                        }
                    }
                    sleep(1);
                    $counter--;
                }

                $this->sendSTX($clientSocket);
                sleep(1);
//                $data = $this->waitForData($clientSocket);
                $this->sendHeader($clientSocket);
                sleep(1);
//                $data = $this->waitForData($clientSocket);
                $result_or_request = rand(0, 1);
                if ($result_or_request) {
                    $number_of_results = rand(1, 5);
                    for ($i = 0; $i < $number_of_results; $i++) {
                        $this->sendResult($clientSocket);
                        sleep(1);
                    }
                } else {
                    $this->sendOrderRequest($clientSocket);
                }
                sleep(1);
//                $data = $this->waitForData($clientSocket);
                $this->sendTerminator($clientSocket);
                sleep(1);
//                $data = $this->waitForData($clientSocket);
                $inc = $this->sendETX($clientSocket);
                if ($inc == self::ACK) {
                    echo "Received ACK\n";
                    sleep(1);
                    $this->sendEOT($clientSocket);
                    if ($this->result_sent) {
                        $idle = true;
                        $counter = 5;
                        $this->result_sent = false;
                        continue;
                    }
                } else {
                    $timeout =$this->checkIfTimeout($clientSocket);
                    if ($timeout) {
                        $this->info("Timeout occurred");
                        continue;
                    }
                }

                $inc = socket_read($clientSocket, 1024);
                if ($inc == self::ENQ) {
                    echo "Received ENQ - incoming order information\n";
                    $this->handleIncomingOrder($clientSocket);
                }

                $counter = 5;
            }

            $this->info("Client disconnected");
            $clientSocket = socket_accept($serverSocket);
            $this->info("Client reconnected");
        }
    }

    private function sendENQ(false|\Socket $clientSocket): void
    {
        socket_write($clientSocket, self::ENQ, strlen(self::ENQ));
        echo "Sent ENQ\n";
    }

    private function sendACK(false|\Socket $clientSocket): void
    {
        socket_write($clientSocket, self::ACK, strlen(self::ACK));
        echo "Sent ACK\n";
    }

    private function sendNAK(false|\Socket $clientSocket): void
    {
        socket_write($clientSocket, HexCodes::NAK->value, strlen(HexCodes::NAK->value));
        echo "Sent NAK\n";
    }

    private function sendSTX(false|\Socket $clientSocket): void
    {
        socket_write($clientSocket, HexCodes::STX->value, strlen(HexCodes::STX->value));
        echo "Sent STX\n";
    }

    private function sendETX(false|\Socket $clientSocket): false|string
    {
        socket_write($clientSocket, HexCodes::ETX->value, strlen(HexCodes::ETX->value));
        echo "Sent ETX\n";
        return socket_read($clientSocket, 1024);
    }

    private function sendEOT(false|\Socket $clientSocket): void
    {
        socket_write($clientSocket, HexCodes::EOT->value, strlen(HexCodes::EOT->value));
        echo "Sent EOT\n";
    }

    private function sendHeader(false|\Socket $clientSocket): void
    {
        $header = bin2hex('H|\^&||PSWD|Maglumi User|||||Lis||P|E1394-97|' . date('Ymd'));
        $checksum = $this->getChecksum($header);
//        echo "Sending header: $header\n";
        echo "Sending header: " . hex2bin($header) . "\n";
//        echo "Header checksum: $checksum\n";
        $header = $header . $checksum;
//        $header = $header . $checksum . self::CR . self::LF;
        socket_write($clientSocket, $header, strlen($header));
    }

    private function sendOrderRequest(false|\Socket $clientSocket): void
    {
        $orderRequest = bin2hex('Q|1|^3218577797||ALL||||||||O');
        $checksum = $this->getChecksum($orderRequest);
//        echo "Sending order request: $orderRequest\n";
        echo "Sending order request: " . hex2bin($orderRequest) . "\n";
//        echo "Order request checksum: $checksum\n";
        $orderRequest = $orderRequest . $checksum;
        socket_write($clientSocket, $orderRequest, strlen($orderRequest));
    }

    private function sendResult(false|\Socket $clientSocket): void
    {
        $result = bin2hex($this->results[array_rand($this->results)]);
        $checksum = $this->getChecksum($result);
//        echo "Sending result: $result\n";
        echo "Sending result: " . hex2bin($result) . "\n";
//        echo "Result checksum: $checksum\n";
        $result = $result . $checksum;
        socket_write($clientSocket, $result, strlen($result));
        $this->result_sent = true;
    }

    private function sendComment(false|\Socket $clientSocket): void
    {
        $comment = bin2hex('C|1|^3218577797|Comment|');
        $checksum = $this->getChecksum($comment);
//        echo "Sending comment: $comment\n";
        echo "Sending comment: " . hex2bin($comment) . "\n";
//        echo "Comment checksum: $checksum\n";
        $comment = $comment . $checksum;
        socket_write($clientSocket, $comment, strlen($comment));
    }

    private function sendTerminator(false|\Socket $clientSocket): void
    {
        $terminator = bin2hex('L|1|N');
        $checksum = $this->getChecksum($terminator);
//        echo "Sending terminator: $terminator\n";
        echo "Sending terminator: " . hex2bin($terminator) . "\n";
//        echo "Terminator checksum: $checksum\n";
        $terminator = $terminator . $checksum;
        socket_write($clientSocket, $terminator, strlen($terminator));
    }

    private function handleIncomingOrder(false|\Socket $clientSocket): void
    {
        $this->info("Handling incoming order");
        sleep(1);
        $this->sendACK($clientSocket);
        $inc = socket_read($clientSocket, 1024);
        if ($inc == self::EOT) {
            sleep(1);
            echo "Order was not found, received EOT\n";
            $this->sendACK($clientSocket);
            return;
        }
//        $inc = socket_read($clientSocket, 1024);
        if ($inc == self::STX) {
            echo "Received STX: $inc\n";
        } else {
            sleep(1);
            $this->sendNAK($clientSocket);
            return;
        }
        // reading short header -------------------------------------------------------------
        $inc = socket_read($clientSocket, 1024);
        if ($inc) {
            $checksum = $this->checkChecksum($inc);
            $inc = substr($inc, 0, -2);
            $inc = hex2bin($inc);
            echo "Received short header: $inc\n";
            // reading patient data -------------------------------------------------------------
            if ($checksum) {
                $inc = socket_read($clientSocket, 1024);
                if ($inc) {
                    $checksum = $this->checkChecksum($inc);
                    $inc = substr($inc, 0, -2);
                    $inc = hex2bin($inc);
                    echo "Received patient data: $inc\n";
                    // reading order data -------------------------------------------------------------
                    if ($checksum) {
                        $inc = socket_read($clientSocket, 1024);
                        if ($inc) {
                            $checksum = $this->checkChecksum($inc);
                            $inc = substr($inc, 0, -2);
                            $inc = hex2bin($inc);
                            echo "Received order data: $inc\n";
                            // reading terminator -------------------------------------------------------------
                            if ($checksum) {
                                $inc = socket_read($clientSocket, 1024);
                                if ($inc) {
                                    $checksum = $this->checkChecksum($inc);
                                    $inc = substr($inc, 0, -2);
                                    $inc = hex2bin($inc);
                                    echo "Received terminator: $inc\n";
                                    if ($checksum) {
                                // reading ETX -------------------------------------------------------------
                                        $inc = socket_read($clientSocket, 1024);
                                        if ($inc == self::ETX) {
                                            echo "Received ETX: $inc\n";
                                        } else {
                                            sleep(1);
                                            $this->sendNAK($clientSocket);
                                            return;
                                        }
                                        // reading EOT -------------------------------------------------------------
                                        $inc = socket_read($clientSocket, 1024);
                                        if ($inc == self::EOT) {
                                            echo "Received EOT: $inc\n";
                                            sleep(1);
                                            $this->sendACK($clientSocket);
                                        }
                                    } else {
                                        sleep(1);
                                        $this->sendNAK($clientSocket);
                                    }
                                } else {
                                    sleep(1);
                                    $this->sendNAK($clientSocket);
                                    return;
                                }
                            } else {
                                sleep(1);
                                $this->sendNAK($clientSocket);
                            }
                        } else {
                            sleep(1);
                            $this->sendNAK($clientSocket);
                        }
                    } else {
                        sleep(1);
                        $this->sendNAK($clientSocket);
                    }
                } else {
                    sleep(1);
                    $this->sendNAK($clientSocket);
                }
            } else {
                sleep(1);
                $this->sendNAK($clientSocket);
            }
        }
    }

    private function getChecksum(string $msg): string
    {
        $checksum = 0;
        for ($i = 0; $i < strlen($msg); $i++) {
            $checksum += ord($msg[$i]);
        }
        $checksum = $checksum & 0xFF;
        return dechex($checksum);
    }

    private function checkChecksum(false|string $inc): bool
    {
        $checksum = substr($inc, -2);
        $inc = substr($inc, 0, -2);
        $checksumCalc = 0;
        for ($i = 0; $i < strlen($inc); $i++) {
            $checksumCalc += ord($inc[$i]);
        }
        $checksumCalc = $checksumCalc & 0xFF;
        $checksumCalc = dechex($checksumCalc);
        return $checksum == $checksumCalc;
    }

    private function waitForData($socket, $timeout = 1): false|string|null
    {
        if (!is_resource($socket)) {
            echo "Error: Invalid socket resource\n";
            return false;
        }
        $read = [$socket];
        $write = null;
        $except = null;

        $numChangedStreams = stream_select($read, $write, $except, $timeout);

        if ($numChangedStreams === false) {
            echo "Error: stream_select failed\n";
            return false;
        } elseif ($numChangedStreams > 0) {
            // Data is available to read
            $data = fread($socket, 1024); // Adjust the buffer size as needed
            echo "Received data: $data\n";
            return $data;
        } else {
            // Timeout occurred, no data available
            echo "No data received within $timeout second(s)\n";
            return null;
        }
    }

    private function checkIfTimeout($clientSocket): bool
    {
        $errorCode = socket_last_error($clientSocket);
        if ($errorCode == SOCKET_ETIMEDOUT) {
            echo "Timeout occurred\n";
            return true;
        }
        return false;
    }
}
