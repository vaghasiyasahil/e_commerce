<?php 

	include_once("config.php");
	$temp = array();


	if($con) {

		$temp['connection'] = 1;

	} else {

		$temp['connection'] = 0;
	}


	$name = $_POST['name'];
	$email = $_POST['email'];
	$password = $_POST['password'];

    $rr = "select * from usertable where EMAIL = '$email'";

    $cheacksql =  mysqli_query($con,$rr);

    $cnt = mysqli_num_rows($cheacksql);

    if($cnt==0) {
    
		// file_put_contents(filename, data)

		$qry = "insert into usertable (NAME,EMAIL,PASSWORD) values ('$name','$email','$password')";


		$sql = mysqli_query($con, $qry);

		if($sql) {

			$temp['result'] = 1;

		} else {

			$temp['result'] = 0;
    	}

	} else {

 		$temp['result'] = 2;
 	}
		
	echo json_encode($temp);
?>