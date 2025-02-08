<?php 
   include_once("config.php");
   $temp   = array();


   if($con)
   {

    $temp['connection']  = 1;

   }
   else {

    $temp['connection']  = 0;
   }

    $email= $_POST['email'];
    $password = $_POST['password'];

    $qry = "select * from UserTable where EMAIL = '$email' and PASSWORD = '$password' ";

    $sql = mysqli_query($con, $qry);

    $cnt = mysqli_num_rows($sql);

    if($cnt==1)
    {
        $temp['result'] = 1;
        //  map 
        $arr = mysqli_fetch_assoc($sql);
        // echo $arr;
        $temp['userdata'] = $arr;
    }
    else
    {
        $temp['result'] = 0;
    }
    echo json_encode($temp);

 ?>

