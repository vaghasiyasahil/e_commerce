<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Include PHPMailer classes manually (you already have these files)
require __DIR__ . '/../package/mail_send/PHPMailer.php';
require __DIR__ . '/../package/mail_send/SMTP.php';
require __DIR__ . '/../package/mail_send/Exception.php';

function sendOTP($toEmail, $otp) {
    
    $mail = new PHPMailer(true);

    try {

        include('../../config.php');

        $sender_data = mysqli_query($con, "SELECT * FROM send_mail WHERE status='true'");
        $row = mysqli_fetch_assoc($sender_data);

        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $row['email'];  // Your Gmail
        $mail->Password   = $row['password'];         // App password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;   // Encryption
        $mail->Port       = 465;

        // Email Details
        $mail->setFrom($row['email'], $row['sender_name']);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $row['email_title'];

        $body = str_replace('$otp', $otp, $row['email_description']);        
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}
