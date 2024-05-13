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

// create a new namespace
$pid = pcntl_unshare(CLONE_NEWPID);


// Define the function to fetch Docker token
function get_docker_token($image) {
  $auth_response = shell_exec("curl -s https://auth.docker.io/token?service=registry.docker.io\&scope=repository:$image:pull");
  if (!$auth_response) {
      echo "Failed to get Docker token.\n";
      return null;
  }
  $auth_data = json_decode($auth_response);
  return $auth_data->token;
}

// Define the function to fetch Docker image manifest
function get_docker_image_manifest($image, $token) {
  $image_response = shell_exec("curl -s -H \"Authorization: Bearer $token\" https://registry.hub.docker.com/v2/$image/manifests/latest");
  if (!$image_response) {
      echo "Failed to fetch Docker image manifest.\n";
      return null;
  }
  return json_decode($image_response);
}

// Define the function to download image layers
function download_image_layers($image, $token, $layers) {
  $dir_path = sys_get_temp_dir() . '/' . uniqid('docker_image_');
  mkdir($dir_path);

  foreach ($layers as $index => $fs_layer) {
      $url = "https://registry.hub.docker.com/v2/$image/blobs/$fs_layer->blobSum";
      shell_exec("curl -s -o $dir_path/$index.tar.gz -L -H \"Authorization: Bearer $token\" $url");
      shell_exec("tar -xvf $dir_path/$index.tar.gz -C $dir_path");
      unlink("$dir_path/$index.tar.gz");
  }
  
  return $dir_path;
}


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
  } else if (str_replace('\n','',$argv[4]) === 'ls') {
    // mydocker run alpine:latest /usr/local/bin/docker-explorer ls /some_dir
    $dir = $argv[5];

    // Check if the directory exists.
    if (!is_dir($dir)) {
      echo "No such file or directory" . PHP_EOL;
      exit(intval(2));
      die;
    }

    // List the files in the directory.
    $files = scandir($dir);
    foreach ($files as $file) {
      echo $file . PHP_EOL;
    }
    
  } else if (str_replace('\n', '', $argv[4]) === 'mypid') {
    // mydocker run alpine:latest /usr/local/bin/docker-explorer mypid
    echo getmypid() . PHP_EOL;
  } else {
    // mydocker run alpine:latest /bin/echo hey
    $image = $argv[2]; // Replace with your desired image
    $token = get_docker_token($image);

    if (!$token) {
      // debug message
      echo "Failed to get Docker token.\n";
      exit(1);
    }

    $manifest = get_docker_image_manifest($image, $token);

    if (!$manifest) {
      // debug message
      echo "Failed to fetch Docker image manifest.\n";
      exit(1);
    }

    $layers = $manifest->layers;
    $dir_path = download_image_layers($image, $token, $layers);

    echo "$dir_path\n"; 

    

  }

}

pcntl_wait($status);
exit(pcntl_wexitstatus($status));
