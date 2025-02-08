<?php
    echo "Start";

    try {
        include_once("config.php");

        // Enable Exception for MySQLi errors
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        // Query execution
        $select = "SELECT * FROM Products";
        $sql = mysqli_query($con, $select);

        // Fetch and display data
        while ($row = mysqli_fetch_array($sql)) {
            echo $row['PRO_NAME'] . "<br>";
            echo $row['PRO_DES'] . "<br>";
        }

    } catch (Exception $e) {
        echo "\nError: " . $e->getMessage();
    }

    echo "\nEnd";
?>
