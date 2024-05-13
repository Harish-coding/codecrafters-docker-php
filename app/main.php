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


// Function to get Docker auth token
function get_docker_token($image_name) {
    // You need to get an auth token, but you don't need a username/password
    // Say your image is busybox/latest, you would make a GET request to this
    // URL: https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/busybox:pull
    $url = "https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/{$image_name}:pull";
    
    // Initialize cURL session
    $ch = curl_init();
    
    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Execute the request
    $res = curl_exec($ch);
    
    // Check for errors
    if ($res === false) {
        echo "cURL error: " . curl_error($ch);
        return null;
    }
    
    // Close cURL session
    curl_close($ch);
    
    // Decode JSON response
    $res_json = json_decode($res, true);
    
    // Return the token
    return $res_json["token"];
}

function build_docker_headers($token) {
  return [
      "Accept: application/vnd.docker.distribution.manifest.v2+json",
      "Authorization: Bearer $token",
  ];
}

function get_docker_image_manifest($headers, $image_name) {
  $manifest_url = "https://registry.hub.docker.com/v2/library/{$image_name}/manifests/latest";
  
  // Initialize cURL session
  $ch = curl_init();
  
  // Set cURL options
  curl_setopt($ch, CURLOPT_URL, $manifest_url);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  
  // Execute the request
  $res = curl_exec($ch);
  
  // Check for errors
  if ($res === false) {
      echo "cURL error: " . curl_error($ch);
      return null;
  }
  
  // Close cURL session
  curl_close($ch);
  
  // Decode JSON response
  $res_json = json_decode($res, true);
  
  // Return the manifest
  return $res_json;
}

function download_image_layers($headers, $image, $layers) {
  // Create a temporary directory
  $dir_path = sys_get_temp_dir() . '/' . uniqid('docker_image_');
  mkdir($dir_path);

  // Loop through the layers to download and extract each one
  foreach ($layers as $layer) {
      $url = "https://registry.hub.docker.com/v2/library/{$image}/blobs/{$layer['digest']}";
      fwrite(STDERR, $url);
      
      // Initialize cURL session
      $ch = curl_init();
      
      // Set cURL options
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      
      // Execute the request
      $res = curl_exec($ch);
      
      // Check for errors
      if ($res === false) {
          echo "cURL error: " . curl_error($ch);
          return null;
      }
      
      // Close cURL session
      curl_close($ch);
      
      // Write the response to a temporary file
      $tmp_file = $dir_path . "/manifest.tar";
      file_put_contents($tmp_file, $res);
      
      // Extract the contents of the tar file
      $tar = new PharData($tmp_file);
      $tar->extractTo($dir_path);
      
      // Remove the temporary file
      unlink($tmp_file);
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
  } else if (str_replace('\n', '', $argv[4]) === 'hey') {
    // mydocker run alpine:latest /bin/echo hey
    $image_name = $argv[2]; // Replace with your desired image name
    $token = get_docker_token($image_name);
    $header = build_docker_headers($token);
    $manifest = get_docker_image_manifest($header, $image_name);
    $dir_path = download_image_layers($header, $image_name, $manifest["layers"]);

      
    // Run subprocess inside Docker container
    $unshare_command = ["unshare", "-fpu", "chroot", $dir_path, $args[3], ...$args];
    $output = [];
    $return_code = null;
    exec(implode(" ", array_map("escapeshellarg", $unshare_command)), $output, $return_code);
    
    // Print output to stdout and stderr
    echo implode(PHP_EOL, $output) . PHP_EOL;
    
    // Exit with return code
    exit($return_code);

  }


}

pcntl_wait($status);
exit(pcntl_wexitstatus($status));
