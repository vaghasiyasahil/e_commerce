<?php
    echo "Start";

    try {
        include_once("config.php");

        // Query execution
        $select = "SELECT * FROM usertable";
        $sql = mysqli_query($con, $select);

        // Fetch and display data
        while ($row = mysqli_fetch_array($sql)) {
            echo $row['name'] . "<br>";
            echo $row['email'] . "<br>";
        }

        // Query execution
        $select = "SELECT * FROM Products";
        $sql = mysqli_query($con, $select);

        // Fetch and display data
        while ($row = mysqli_fetch_array($sql)) {
            echo $row['PRO_NAME'] . "<br>";
            echo $row['PRO_DES'] . "<br>";
        }

    } catch (Exception $e) {
        echo "<br>Error: " . $e->getMessage();
    }

    echo "<br>End";
?>
