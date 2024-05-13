<?php
// Usage: your_docker.sh run <image> <command> <arg1> <arg2> ...

// Disable output buffering.
while (ob_get_level() !== 0) {
  ob_end_clean();
}

// Create a temporary directory and copy necessary files. Inspired from FasterCoderOnEarth (2021)
if (!is_dir('./temporary')) mkdir("./temporary", 0700);
if (!is_dir('./temporary/bin')) mkdir("./temporary/bin", 0700);
if (!is_dir('./temporary/lib')) mkdir("./temporary/lib", 0700);
if (!is_dir('./temporary/usr/local/bin')) mkdir("./temporary/usr/local/bin", 0700, TRUE);
shell_exec("cp -r /bin ./temporary");
shell_exec("cp -r /lib ./temporary");
shell_exec("cp -r /usr/local/bin/docker-explorer ./temporary/usr/local/bin");
chroot("./temporary");

// You can use print statements as follows for debugging, they'll be visible when running tests.
// echo "Logs from your program will appear here!\n";

// Uncomment this to pass the first stage.
$child_pid = pcntl_fork();
if ($child_pid == -1) {
  echo "Error forking!";
}
elseif ($child_pid) {
  // We're in parent.
  pcntl_wait($status);
  // echo "Child terminates!";
}
else {
  // Replace current program with calling program.
  // mydocker run alpine:latest /usr/local/bin/docker-explorer exit 1
  
  $commands = implode(' ', array_slice($argv, 3));

  if (str_replace('\n','',$argv[4]) === 'echo') {
    fputs(STDOUT, $argv[5] . PHP_EOL);
  } else if (str_replace('\n','',$argv[4]) === 'echo_stderr') {
    fputs(STDERR, $argv[5] . PHP_EOL);
  } else if (str_replace('\n','',$argv[4]) === 'exit') {
    exit(intval($argv[5]));
  }

  // Execute the command.

}

pcntl_wait($status);
exit(pcntl_wexitstatus($status));
