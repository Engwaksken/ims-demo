<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail-function.php';

header(
    'Content-Type: application/json; charset=utf-8'
);

function booking_email_json(
    bool $success,
    string $message,
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
        ],
        JSON_UNESCAPED_SLASHES
        |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

if (
    !isset($_SESSION['user_id'])
    ||
    !ims_allowed('bookings.send_email', ['Administrator', 'Operations/Admin'], 'Send booking emails')
) {
    booking_email_json(
        false,
        'Access denied.',
        403
    );
}

if (
    ($_SERVER['REQUEST_METHOD'] ?? '')
    !==
    'POST'
) {
    booking_email_json(
        false,
        'Invalid request method.',
        405
    );
}

if (
    !isset($conn)
    ||
    !($conn instanceof mysqli)
) {
    booking_email_json(
        false,
        'Database connection unavailable.',
        500
    );
}

$conn->set_charset('utf8mb4');

$bookingId =
    max(
        0,
        (int)(
            $_POST['booking_id']
            ?? 0
        )
    );

$subject =
    trim(
        (string)(
            $_POST['subject']
            ?? ''
        )
    );

$message =
    trim(
        (string)(
            $_POST['message']
            ?? ''
        )
    );

if ($bookingId <= 0) {
    booking_email_json(
        false,
        'Invalid booking selected.',
        422
    );
}

if (
    $subject === ''
    ||
    $message === ''
) {
    booking_email_json(
        false,
        'Subject and message are required.',
        422
    );
}

if (
    mb_strlen(
        $subject
    )
    >
    180
) {
    booking_email_json(
        false,
        'Subject is too long.',
        422
    );
}

if (
    mb_strlen(
        $message
    )
    >
    10000
) {
    booking_email_json(
        false,
        'Message is too long.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| Resolve recipient securely from booking -> member -> user
|--------------------------------------------------------------------------
|
| Do not trust an email address posted from the browser.
|
*/

$stmt =
    $conn->prepare("
        SELECT
            sb.booking_id,
            sb.space_name,
            sb.space_type,
            sb.booking_date,
            sb.start_time,
            sb.end_time,
            sb.booking_status,
            m.membership_number,
            u.full_name AS member_name,
            u.email AS member_email
        FROM space_bookings sb
        INNER JOIN members m
            ON m.member_id = sb.member_id
        INNER JOIN users u
            ON u.user_id = m.user_id
        WHERE sb.booking_id = ?
        LIMIT 1
    ");

if (!$stmt) {
    booking_email_json(
        false,
        'Could not prepare booking lookup.',
        500
    );
}

$stmt->bind_param(
    'i',
    $bookingId
);

$stmt->execute();

$booking =
    $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$booking) {
    booking_email_json(
        false,
        'Booking was not found.',
        404
    );
}

$recipient =
    trim(
        (string)(
            $booking['member_email']
            ?? ''
        )
    );

$memberName =
    trim(
        (string)(
            $booking['member_name']
            ?? 'Member'
        )
    );

if (
    !filter_var(
        $recipient,
        FILTER_VALIDATE_EMAIL
    )
) {
    booking_email_json(
        false,
        'The member does not have a valid email address.',
        422
    );
}

/*
|--------------------------------------------------------------------------
| Build safe HTML email
|--------------------------------------------------------------------------
*/

$safeMessage =
    nl2br(
        htmlspecialchars(
            $message,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        )
    );

$bookingSummary =
    '
    <div class="info-box">
        <div>
            <strong>Booking ID:</strong>
            #'
            . (int)$booking['booking_id']
            . '
        </div>

        <div>
            <strong>Space:</strong>
            '
            . htmlspecialchars(
                (string)(
                    $booking['space_name']
                    ?? ''
                ),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            )
            . '
        </div>

        <div>
            <strong>Date:</strong>
            '
            . htmlspecialchars(
                (string)(
                    $booking['booking_date']
                    ?? ''
                ),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            )
            . '
        </div>

        <div>
            <strong>Time:</strong>
            '
            . htmlspecialchars(
                substr(
                    (string)(
                        $booking['start_time']
                        ?? ''
                    ),
                    0,
                    5
                ),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            )
            . '
            -
            '
            . htmlspecialchars(
                substr(
                    (string)(
                        $booking['end_time']
                        ?? ''
                    ),
                    0,
                    5
                ),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            )
            . '
        </div>
    </div>
    ';

$content =
    '
    <h3>Hive Colab Booking Update</h3>

    <p>
        Dear '
        . htmlspecialchars(
            $memberName,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        )
        . ',
    </p>

    '
    . $bookingSummary
    . '

    <div style="
        margin-top:15px;
        line-height:1.7;
    ">
        '
        . $safeMessage
        . '
    </div>

    <p style="margin-top:18px;">
        Best regards,<br>
        <strong>Hive Colab Operations</strong>
    </p>
    ';

$htmlBody =
    function_exists('email_wrapper')
        ? email_wrapper(
            $content
        )
        : $content;

$altBody =
    "Dear {$memberName},\n\n"
    . "Booking ID: #"
    . (int)$booking['booking_id']
    . "\n"
    . "Space: "
    . ($booking['space_name'] ?? '')
    . "\n"
    . "Date: "
    . ($booking['booking_date'] ?? '')
    . "\n"
    . "Time: "
    . substr(
        (string)(
            $booking['start_time']
            ?? ''
        ),
        0,
        5
    )
    . ' - '
    . substr(
        (string)(
            $booking['end_time']
            ?? ''
        ),
        0,
        5
    )
    . "\n\n"
    . $message
    . "\n\nHive Colab Operations";

try {
    $sent =
        sendEmail(
            $recipient,
            $subject,
            $htmlBody,
            $altBody
        );

    /*
     * Some existing mail helper versions return void.
     * Treat only an explicit false as failure.
     */
    if ($sent === false) {
        throw new RuntimeException(
            'Mail service reported that the email was not sent.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Activity log when available
    |--------------------------------------------------------------------------
    */

    if (
        function_exists(
            'logActivity'
        )
    ) {
        try {
            logActivity(
                $conn,
                (int)$_SESSION['user_id'],
                'Email Booking Member',
                'Sent booking email for booking #'
                . $bookingId
                . ' to '
                . $recipient,
                $_SERVER['REMOTE_ADDR']
                ?? ''
            );
        } catch (Throwable $e) {
            error_log(
                'Booking email activity log failed: '
                . $e->getMessage()
            );
        }
    }

    booking_email_json(
        true,
        'Email sent successfully to '
        . $recipient
        . '.'
    );
} catch (Throwable $e) {
    error_log(
        'Booking member email failed for booking #'
        . $bookingId
        . ': '
        . $e->getMessage()
    );

    booking_email_json(
        false,
        'The email could not be sent. Please check the IMS mail configuration and try again.',
        500
    );
}
