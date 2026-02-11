<?php

echo  "hello, world :)";

#variables
$phpClass = "<br><br>Interactive Workshop!";

$graduationDate = 2029;

echo '<br>' . $phpClass . "for students graduating in " . $graduationDate ;

// conditions 
$is_logged_in = false;

if ($is_logged_in) {
    echo "<br><br>Welcome, to our workshop!";
} else {
    echo "<br><br>Please log in fist";
}

// Functions 

$name = "Khushi";

function greeting($name) {
    return "<br> Hello, " . $name; 
}

echo greeting($name) . "<br><br><br>";

?>

<!DOCTYPE html>
<form method="POST">
    <input type="text" name="command" placeholder="Enter command">
    <button type="submit">Submit</button>
</form>
</html>

<?php 

if(isset($_POST['command'])) {
    echo "You typed: " . $_POST['command'];
}

$command = htmlspecialchars($_POST['command']);

?>