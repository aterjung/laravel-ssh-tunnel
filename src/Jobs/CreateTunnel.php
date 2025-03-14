<?php namespace STS\Tunneler\Jobs;

class CreateTunnel
{

    /**
     * The Command for checking if the tunnel is open
     * @var string
     */
    protected $ncCommand;

    /**
     * The command for creating the tunnel
     * @var string
     */
    protected $sshCommand;

    /**
     * Simple place to keep all output.
     * @var array
     */
    protected $output = [];

    public function __construct()
    {

        $this->ncCommand = sprintf('%s -vz %s %d  > /dev/null 2>&1',
            config('tunneler.nc_path'),
            config('tunneler.local_address'),
            config('tunneler.local_port')
        );

        $this->bashCommand = sprintf('timeout 1 %s -c \'cat < /dev/null > /dev/tcp/%s/%d\' > /dev/null 2>&1',
            config('tunneler.bash_path'),
            config('tunneler.local_address'),
            config('tunneler.local_port')
        );

        $this->sshCommand = sprintf('%s %s %s -N -i %s -L %d:%s:%d -p %d %s@%s',
            config('tunneler.ssh_path'),
            config('tunneler.ssh_options'),
            config('tunneler.ssh_verbosity'),
            config('tunneler.identity_file'),
            config('tunneler.local_port'),
            config('tunneler.bind_address'),
            config('tunneler.bind_port'),
            config('tunneler.port'),
            config('tunneler.user'),
            config('tunneler.hostname')
        );
    }


    public function handle(): int
    {
        if ($this->verifyTunnel()) {
            return 1;
        }

        $this->createTunnel();

        $tries = config('tunneler.tries');
        for ($i = 0; $i < $tries; $i++) {
            if ($this->verifyTunnel()) {
                return 2;
            }

            // Wait a bit until next iteration
            usleep(config('tunneler.wait'));
        }

        throw new \ErrorException(sprintf("Could Not Create SSH Tunnel with command:\n\t%s\nCheck your configuration.",
            $this->sshCommand));
    }


    /**
     * Creates the SSH Tunnel for us.
     */
    protected function createTunnel()
    {
        if (config('tunneler.create') && config('tunneler.create') === 'exec') {
            $logPath = config('tunneler.nohup_log');

            $command = sprintf('%s >> %s 2>&1 &', $this->sshCommand, $logPath);

            // make nohup optional
            if (config('tunneler.nohup_path')) {
                $command = sprintf('%s %s >> %s 2>&1 &', config('tunneler.nohup_path'), $this->sshCommand, $logPath);
            }

            $this->runCommand($command);
        } else {
            $this->createTunnelPOpen();
        }


        usleep(config('tunneler.wait'));
    }

    /**
     * Starts the SSH Tunnel using proc_open().
     */
    protected function createTunnelPOpen()
    {
        $descriptorspec = [
            0 => ['pipe', 'r'], // Standard Input
            1 => ['file', config('tunneler.nohup_log'), 'a'], // Standard Output
            2 => ['file', config('tunneler.nohup_log'), 'a'], // Standard Error
        ];

        $process = proc_open(
            $this->sshCommand,
            $descriptorspec,
            $pipes
        );

        if (!is_resource($process)) {
            throw new \RuntimeException("Unable to start SSH Tunnel process.");
        }
    }

    /**
     * Verifies whether the tunnel is active or not.
     * @return bool
     */
    protected function verifyTunnel()
    {
        $verifyProcess = config('tunneler.verify_process');

        if ($verifyProcess === 'php') {
            return $this->phpVerifyTunnel();
        } elseif ($verifyProcess === 'bash') {
            return $this->runCommand($this->bashCommand);
        }

        return $this->runCommand($this->ncCommand);
    }


    /**
     * Tunnel-Verify using PHP-Sockets
     * @return bool
     */
    protected function phpVerifyTunnel()
    {
        $host = config('tunneler.local_address');
        $port = config('tunneler.local_port');

        $connection = @fsockopen($host, $port, $errno, $errstr, 1);

        if ($connection) {
            fclose($connection);
            return true;
        }

        return false;
    }

    public function destroyTunnel(){
        $destroyProcess = config('tunneler.destroy_process');

        if ($destroyProcess === 'php') {
            $this->destroyTunnelPhp();
        } else {
            $this->destroyTunnelPkill();
        }
    }

    /*
     * Use pkill to kill the SSH tunnel
     */

    private function destroyTunnelPkill()
    {
        $ssh_command = preg_replace('/[\s]{2}[\s]*/', ' ', $this->sshCommand);
        return $this->runCommand('pkill -f "' . $ssh_command . '"');
    }

    private function destroyTunnelPhp()
    {
        $ssh_command = preg_replace('/[\s]{2}[\s]*/', ' ', $this->sshCommand);

        $descriptorspec = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open('ps -eo pid,args', $descriptorspec, $pipes);

        // Get all running processes using the "ps aux" command
        $output = '';
        if (is_resource($process)) {
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            proc_close($process);
        }
        $output = preg_split("/\r\n|\n|\r/", $output);

        foreach ($output as $line) {
            // Look for the SSH command in the process list
            if (strpos($line, $ssh_command) !== false) {
                // Split the line to extract the PID of the process
                $parts = preg_split('/\s+/', trim($line));

                if (isset($parts[0]) && is_numeric($parts[0])) {
                    $pid = (int)$parts[0];

                    // Kill the process with the identified PID
                    if (posix_kill($pid, SIGKILL)) {
                        echo "Tunnel process with PID $pid has been terminated.\n";
                        return true;
                    } else {
                        echo "Error occurred while trying to terminate the process with PID $pid.\n";
                        return false;
                    }
                }
            }
        }

        echo "No tunnel process found.\n";
        return false;
    }

    /**
     * Runs a command and converts the exit code to a boolean
     * @param $command
     * @return bool
     */
    protected function runCommand($command)
    {
        $return_var = 1;
        exec($command, $this->output, $return_var);
        return (bool)($return_var === 0);
    }


}
