<?php

namespace App\Console\Commands\Tests;

use App\Enums\HexCodes;
use Illuminate\Console\Command;

class StartHL7AnalyzerCommand extends Command
{
    const CR = HexCodes::CR->value;
    const VT = HexCodes::VT->value;
    const FS = HexCodes::FS->value;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hl:connect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts the hl client';

    /**
     * Execute the console command.
     */

    private string $str = 'MSH|^~\&|BC-5380|Mindray|||20080617143943||ORU^R01|1|P|2.3.1||||||UNICODE' . self::CR .
    'PID|1||7393670^^^^MR||Joan^JIang||19900804000000|Female' . self::CR .
    'PV1|1||nk^^001' . self::CR .
    'OBR|1||20071207011|00001^Automated Count^99MRC||20080508140600|20080508150616|||John||||20080508150000||||||||||HM||||||||Mindray' . self::CR .
    'OBX|2|NM|6690-2^WBC^LN||9.81|10*9/L|4.00-10.00|N|||F||E' . self::CR .
    'OBX|3|NM|704-7^BAS#^LN|||10*9/L|0.00-0.10||||F' . self::CR .
    'OBX|4|NM|706-2^BAS%^LN||||0.000-0.010||||F' . self::CR .
    'OBX|5|NM|751-8^NEU#^LN|||10*9/L|2.00-7.00||||F' . self::CR .
    'OBX|6|NM|770-8^NEU%^LN||||0.500-0.700||||F' . self::CR .
    'OBX|7|NM|711-2^EOS#^LN|||10*9/L|0.02-0.50||||F' . self::CR .
    'OBX|8|NM|713-8^EOS%^LN||||0.005-0.050||||F' . self::CR .
    'OBX|9|NM|731-0^LYM#^LN|||10*9/L|0.80-4.00||||F' . self::CR .
    'OBX|10|NM|736-9^LYM%^LN||||0.200-0.400||||F' . self::CR .
    'OBX|11|NM|742-7^MON#^LN|||10*9/L|0.12-0.80||||F' . self::CR .
    'OBX|12|NM|5905-5^MON%^LN||||0.030-0.080||||F' . self::CR .
    'OBX|13|NM|26477-0^*ALY#^LN|||10*9/L|0.00-0.20||||F' . self::CR .
    'OBX|14|NM|13046-8^*ALY%^LN||||0.000-0.020||||F' . self::CR .
    'OBX|15|NM|10000^*LIC#^99MRC|||10*9/L|0.00-0.20||||F' . self::CR .
    'OBX|16|NM|10001^*LIC%^99MRC||||0.000-0.025||||F' . self::CR .
    'OBX|17|NM|789-8^RBC^LN||4.53|10*12/L|3.50-5.00|N|||F' . self::CR .
    'OBX|18|NM|718-7^HGB^LN||65|g/L|110-150|L|||F' . self::CR .
    'OBX|19|NM|787-2^MCV^LN||89.5|fL|80.0-100.0|N|||F' . self::CR .
    'OBX|20|NM|785-6^MCH^LN||14.4|pg|27.0-31.0|L|||F' . self::CR .
    'OBX|21|NM|786-4^MCHC^LN||160|g/L|320-360|L|||F' . self::CR .
    'OBX|22|NM|788-0^RDW-CV^LN||0.133||0.115-0.145|N|||F' . self::CR .
    'OBX|23|NM|21000-5^RDW-SD^LN||50.9|fL|35.0-56.0|N|||F' . self::CR .
    'OBX|24|NM|4544-3^HCT^LN||0.405||0.370-0.480|N|||F' . self::CR .
    'OBX|25|NM|777-3^PLT^LN||212|10*9/L|100-300|N|||F' . self::CR .
    'OBX|26|NM|32623-1^MPV^LN||6.6|fL|7.0-11.0|L|||F' . self::CR .
    'OBX|27|NM|32207-3^PDW^LN||15.4||15.0-17.0|N|||F' . self::CR .
    'OBX|28|NM|10002^PCT^99MRC||1.40|mL/L|1.08-2.82|N|||F' . self::CR;


    public function handle(): void
    {
        $this->info('HL7 Analyzer Command');

        $ip = '192.168.0.111';
        $port = 9999;


        $this->info('Connecting to ' . $ip . ' on port ' . $port);

        $counter = 0;
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $connection = @socket_connect($socket, $ip, $port);

        if ($connection) {
            while (true) {
                $message = $this->str;
                $message = self::VT . $message . self::FS . self::CR;
                $message = bin2hex($message);
                socket_write($socket, $message, strlen($message));
                $this->info('Sent: ' . $message);
                $resp = socket_read($socket, 2048);
                $this->info('Received: ' . $resp);
                sleep(30);
            }
        }
    }
}
