<?php

// =====================================
// PHPMailer Includes
// =====================================
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


// =====================================
// SEND WELCOME EMAIL
// =====================================
function sendWelcomeEmail(
    $toEmail,
    $customerId,
    $websiteName,
    $password = null,
    $isExistingUser = false
) {

    $mail = new PHPMailer(true);

    try {

        // =====================================
        // SMTP SETTINGS (GoDaddy)
        // =====================================
        $mail->isSMTP();

        $mail->Host       = 'smtpout.secureserver.net';
        $mail->SMTPAuth   = true;

        $mail->Username   = 'info@codrant.com';

        // =====================================
        // REPLACE PASSWORD
        // =====================================
        $mail->Password   = 'Asawari@1967';

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // =====================================
        // EMAIL SETTINGS
        // =====================================
        $mail->setFrom(
            'info@codrant.com',
            'Vani AI'
        );

        $mail->addReplyTo(
            'info@codrant.com',
            'Vani AI Support'
        );

        $mail->addAddress($toEmail);

        $mail->isHTML(true);

        $mail->Subject = $isExistingUser
            ? 'Your Existing Vani AI Account'
            : 'Your Vani AI Chatbot is Ready';

        $loginUrl = "https://vani.codrant.com/login.php";

        // =====================================
        // PASSWORD ROW
        // =====================================
        $passwordRow = '';

        if (!$isExistingUser && $password) {

            $passwordRow = '
            <tr class="row">

              <td class="label">
                Password
              </td>

              <td class="value">
                ' . htmlspecialchars($password) . '
              </td>

            </tr>';
        }

        // =====================================
        // RESPONSIVE EMAIL DESIGN
        // =====================================
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>

          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">

          <style>

            body{
              margin:0;
              padding:0;
              background:#f3f4f6;
              font-family:Arial,sans-serif;
            }

            table{
              border-spacing:0;
            }

            .wrapper{
              width:100%;
              table-layout:fixed;
              background:#f3f4f6;
              padding:20px 10px;
            }

            .main{
              background:#ffffff;
              margin:0 auto;
              width:100%;
              max-width:620px;
              border-radius:20px;
              overflow:hidden;
              box-shadow:0 10px 35px rgba(0,0,0,0.08);
            }

            .hero{
              background:linear-gradient(135deg,#667eea,#764ba2);
              padding:50px 30px;
              text-align:center;
              color:#ffffff;
            }

            .hero h1{
              margin:0;
              font-size:34px;
              line-height:1.3;
            }

            .hero p{
              margin-top:14px;
              font-size:16px;
              line-height:1.7;
              opacity:0.95;
            }

            .content{
              padding:40px 30px;
            }

            .title{
              margin-top:0;
              color:#111827;
              font-size:24px;
              line-height:1.4;
            }

            .desc{
              color:#6b7280;
              font-size:15px;
              line-height:1.8;
            }

            .card{
              margin-top:25px;
              border:1px solid #e5e7eb;
              border-radius:14px;
              overflow:hidden;
            }

            .row{
              border-bottom:1px solid #e5e7eb;
            }

            .row:last-child{
              border-bottom:none;
            }

            .label{
              background:#f9fafb;
              padding:16px 18px;
              font-weight:600;
              color:#111827;
              font-size:14px;
              width:160px;
            }

            .value{
              padding:16px 18px;
              color:#374151;
              font-size:14px;
              word-break:break-word;
            }

            .btn-wrap{
              text-align:center;
              margin-top:40px;
            }

            .btn{
              display:inline-block;
              background:#4f6aff;
              color:#ffffff !important;
              text-decoration:none;
              padding:15px 30px;
              border-radius:12px;
              font-size:15px;
              font-weight:600;
            }

            .security{
              margin-top:35px;
              padding:18px;
              background:#f9fafb;
              border-radius:12px;
              color:#6b7280;
              font-size:14px;
              line-height:1.8;
            }

            .footer{
              background:#f9fafb;
              text-align:center;
              padding:25px;
              color:#9ca3af;
              font-size:13px;
              line-height:1.7;
            }

            @media screen and (max-width:600px){

              .hero{
                padding:40px 22px;
              }

              .hero h1{
                font-size:28px;
              }

              .hero p{
                font-size:15px;
              }

              .content{
                padding:30px 20px;
              }

              .title{
                font-size:22px;
              }

              .label,
              .value{
                display:block;
                width:100%;
                box-sizing:border-box;
              }

              .label{
                border-bottom:1px solid #e5e7eb;
              }

              .btn{
                width:100%;
                box-sizing:border-box;
                text-align:center;
              }

            }

            @media screen and (max-width:420px){

              .hero h1{
                font-size:24px;
              }

              .content{
                padding:24px 16px;
              }

              .title{
                font-size:20px;
              }

              .desc,
              .security{
                font-size:14px;
              }

            }

          </style>

        </head>

        <body>

          <div class="wrapper">

            <table class="main" width="100%">

              <!-- HERO -->
              <tr>
                <td class="hero">

                  <h1>
                    🎉 Welcome to Vani AI
                  </h1>

                  <p>
                    Your chatbot setup has been completed successfully.
                  </p>

                </td>
              </tr>

              <!-- CONTENT -->
              <tr>
                <td class="content">

                  <h2 class="title">
                    ' . ($isExistingUser
                        ? 'Your Existing Vani AI Account'
                        : 'Your Login Credentials') . '
                  </h2>

                  <p class="desc">
                    ' . ($isExistingUser
                        ? 'We found an existing account associated with this email.'
                        : 'Use the credentials below to access your chatbot dashboard securely.') . '
                  </p>

                  <!-- CARD -->
                  <table width="100%" class="card">

                    <tr class="row">

                      <td class="label">
                        Customer ID
                      </td>

                      <td class="value">
                        ' . htmlspecialchars($customerId) . '
                      </td>

                    </tr>

                    <tr class="row">

                      <td class="label">
                        Website
                      </td>

                      <td class="value">
                        ' . htmlspecialchars($websiteName) . '
                      </td>

                    </tr>

                    ' . $passwordRow . '

                  </table>

                  <!-- BUTTON -->
                  <div class="btn-wrap">

                    <a href="' . $loginUrl . '" class="btn">
                      Login Dashboard →
                    </a>

                  </div>

                  <!-- SECURITY -->
                  <div class="security">

                    🔒 Keep your credentials secure.<br>
                    You can change your password anytime after login.

                  </div>

                </td>
              </tr>

              <!-- FOOTER -->
              <tr>
                <td class="footer">

                  © ' . date("Y") . ' Vani AI by Codrant<br>
                  https://vani.codrant.com

                </td>
              </tr>

            </table>

          </div>

        </body>
        </html>
        ';

        // =====================================
        // SEND EMAIL
        // =====================================
        return $mail->send();

    } catch (Exception $e) {

        error_log("Mailer Error: " . $mail->ErrorInfo);

        return false;
    }
}