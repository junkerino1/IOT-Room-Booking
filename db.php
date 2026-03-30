<?php

$conn = mysqli_connect('localhost','root','','iot-booking');

if($conn == false){
    die("Connection Error:". mysqli_connect_error());

}
