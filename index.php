<?php
declare(strict_types=1);
session_start();
mysqli_report(MYSQLI_REPORT_OFF);

/*
 * FINAL YEAR PROJECT — Student Marks Publication via USSD
 * Save as: index.php
 * Requirements: PHP 8+, MySQL/MariaDB, XAMPP Apache + MySQL, cURL PHP extension for SMS.
 *
 * First-run demo accounts are created automatically:
 * HOD:      hod / Hod@12345
 * Lecturer: lecturer / Lec@12345
 * Change these passwords after logging in.
 */

const DB_HOST = 'localhost';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'final year project';

/* Configure your own Africa's Talking credentials. Do not put a real API key in public code. */
const AT_USERNAME = 'sandbox';
const AT_API_KEY = 'PUT_YOUR_AFRICAS_TALKING_API_KEY_HERE';
const AT_SENDER_ID = '';
const AT_SMS_URL = 'https://api.sandbox.africastalking.com/version1/messaging';
const USSD_CODE = '*384*YOUR_SHORTCODE#';

function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function phone_normalize(string $phone): string {
    $phone = preg_replace('/[\s\-().]/', '', trim($phone)) ?? '';
    if (str_starts_with($phone, '00')) $phone = '+' . substr($phone, 2);
    if (str_starts_with($phone, '07') && strlen($phone) === 10) $phone = '+250' . substr($phone, 1);
    if (str_starts_with($phone, '7') && strlen($phone) === 9) $phone = '+250' . $phone;
    return $phone;
}
function ussd_end(string $message, bool $continue = false): never {
    header('Content-Type: text/plain; charset=UTF-8');
    echo ($continue ? 'CON ' : 'END ') . $message;
    exit;
}
function send_sms(string $phone, string $message): array {
    if (AT_API_KEY === 'PUT_YOUR_AFRICAS_TALKING_API_KEY_HERE' || AT_API_KEY === '') {
        return ['status'=>'NOT_CONFIGURED', 'response'=>'Add your Africa\'s Talking API key in index.php.'];
    }
    if (!function_exists('curl_init')) return ['status'=>'FAILED', 'response'=>'PHP cURL extension is not enabled.'];
    $data = ['username'=>AT_USERNAME, 'to'=>phone_normalize($phone), 'message'=>$message];
    if (AT_SENDER_ID !== '') $data['from'] = AT_SENDER_ID;
    $ch = curl_init(AT_SMS_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($data),
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25,
        CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/x-www-form-urlencoded','apiKey: '.AT_API_KEY]
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error !== '') return ['status'=>'FAILED','response'=>$error];
    return ['status'=>($code >= 200 && $code < 300) ? 'SENT' : 'FAILED','response'=>(string)$response];
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
if ($conn->connect_error) { http_response_code(500); die('Database connection failed. Start MySQL in XAMPP and check DB settings.'); }
$conn->set_charset('utf8mb4');
if (!$conn->select_db(DB_NAME)) {
    http_response_code(500);
    die('Database not found. Import database.sql first in phpMyAdmin. Database name must be exactly: ' . e(DB_NAME));
}

/* Seed demo accounts if missing. These are demonstration credentials; change them immediately. */
$seed = [
    ['hod', 'Hod@12345', 'HOD'],
    ['lecturer', 'Lec@12345', 'LECTURER']
];
foreach ($seed as [$username,$password,$role]) {
    $q=$conn->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
    $q->bind_param('s',$username); $q->execute(); $exists=$q->get_result()->fetch_assoc(); $q->close();
    if (!$exists) {
        $hash=password_hash($password, PASSWORD_DEFAULT);
        $department='Information and Communication Technology (ICT)';
        $q=$conn->prepare('INSERT INTO users(username,password_hash,role,department) VALUES(?,?,?,?)');
        $q->bind_param('ssss',$username,$hash,$role,$department); $q->execute(); $q->close();
    }
}

/* Africa's Talking sends sessionId, serviceCode, phoneNumber and text as POST values. */
$isUssd = isset($_POST['sessionId']) || isset($_POST['ussdSessionId']);
if ($isUssd) {
    $raw = trim((string)($_POST['text'] ?? ''));
    $parts = $raw === '' ? [] : explode('*', $raw);
    // Menu: 1 -> registration number -> password -> semester -> published marks.
    if (count($parts) === 0) ussd_end("FINAL YEAR PROJECT\n1. Check published marks\n0. Exit", true);
    if ($parts[0] === '0') ussd_end('Thank you for using the Student Results Service.');
    if ($parts[0] !== '1') ussd_end('Invalid option. Dial again.');
    if (count($parts) === 1) ussd_end('Enter your registration number:', true);
    if (count($parts) === 2) {
        if (trim($parts[1]) === '') ussd_end('Registration number is required. Dial again.');
        ussd_end('Enter your student password:', true);
    }
    if (count($parts) === 3) {
        ussd_end("Select semester:\n1. Semester 1\n2. Semester 2", true);
    }
    if (count($parts) < 4 || !in_array($parts[3], ['1','2'], true)) ussd_end('Invalid semester. Dial again.');
    $reg = trim($parts[1]); $password = $parts[2]; $semester=(int)$parts[3];
    $q=$conn->prepare('SELECT id,reg_number,full_name,password_hash,department,class_level FROM students WHERE reg_number=? LIMIT 1');
    $q->bind_param('s',$reg); $q->execute(); $student=$q->get_result()->fetch_assoc(); $q->close();
    if (!$student || !password_verify($password, $student['password_hash'])) ussd_end('Invalid registration number or password. Please try again.');
    $q=$conn->prepare("SELECT subject_code,subject_name,marks FROM marks WHERE student_id=? AND semester=? AND status='PUBLISHED' ORDER BY subject_name");
    $q->bind_param('ii',$student['id'],$semester); $q->execute(); $rs=$q->get_result();
    $message="RESULTS\n".$student['full_name']."\nReg: ".$student['reg_number']."\nSemester: ".$semester."\n";
    $count=0; $total=0.0;
    while ($m=$rs->fetch_assoc()) {
        $count++; $total+=(float)$m['marks'];
        if ($count <= 7) $message.="\n".$m['subject_code']." ".$m['subject_name'].": ".number_format((float)$m['marks'],2)."%";
    }
    $q->close();
    if ($count===0) $message.="\nNo marks have been published for this semester.";
    else {
        if ($count>7) $message.="\nMore subjects exist; ask your department for details.";
        $message.="\nAverage: ".number_format($total/$count,2)."%";
    }
    ussd_end($message);
}

$notice=''; $error='';
function logged_in(): bool { return isset($_SESSION['user_id'], $_SESSION['role']); }
function go(string $page='dashboard'): never { header('Location: index.php?page='.rawurlencode($page)); exit; }

/* Login and logout */
if (isset($_GET['logout'])) { $_SESSION=[]; session_destroy(); header('Location: index.php'); exit; }
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['login'])) {
    $username=trim((string)($_POST['username']??'')); $password=(string)($_POST['password']??''); $selectedDepartment=trim((string)($_POST['department']??''));
    $q=$conn->prepare('SELECT id,username,password_hash,role,department FROM users WHERE username=? LIMIT 1');
    $q->bind_param('s',$username); $q->execute(); $user=$q->get_result()->fetch_assoc(); $q->close();
    if ($user && password_verify($password,$user['password_hash']) && hash_equals((string)$user['department'], $selectedDepartment)) {
        session_regenerate_id(true); $_SESSION['user_id']=(int)$user['id']; $_SESSION['username']=$user['username'];
        $_SESSION['role']=$user['role']; $_SESSION['department']=$user['department']; go('dashboard');
    } else $error='Invalid username, password, or department. Choose the department assigned to your account.';
}
if (!logged_in()) {
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Final Year Project | Login</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:linear-gradient(135deg,#eaf1fb,#f6f8fc);color:#172033;min-height:100vh;display:grid;place-items:center}.login{width:min(440px,92vw);background:white;padding:32px;border-radius:16px;box-shadow:0 12px 38px #1b35551c}h1{margin:0;color:#173f70}p{color:#64748b}label{display:block;font-weight:700;margin:16px 0 7px}input,select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;font-size:16px}button,.btn{background:#174a7e;color:white;border:0;border-radius:8px;padding:12px 16px;cursor:pointer;text-decoration:none;display:inline-block}button{width:100%;margin-top:20px}.err{background:#fee2e2;color:#991b1b;padding:12px;border-radius:8px}.hint{background:#eff6ff;padding:12px;border-radius:8px;font-size:13px;line-height:1.7;margin-top:18px}
</style></head><body><main class="login"><h1>Final Year Project</h1><p>Student Marks Publication System</p><?php if($error!=='') echo '<div class="err">'.e($error).'</div>'; ?>
<form method="post"><label>Username</label><input name="username" required autocomplete="username"><label>Password</label><input name="password" type="password" required autocomplete="current-password"><label>Department</label><select name="department" required><option value="">-- Select Department --</option><option>Information and Communication Technology (ICT)</option><option>Business Management</option><option>Electrical Technology</option><option>Mechanical Engineering</option><option>Civil Engineering</option></select><button name="login" value="1">Login</button></form>
<div class="hint"><b>First-run demo accounts</b><br>HOD: <code>hod</code> / <code>Hod@12345</code><br>Lecturer: <code>lecturer</code> / <code>Lec@12345</code><br>Change these passwords after logging in.</div></main></body></html>
<?php exit; }

/* Only HOD can approve results. Lecturer can enter marks and register students. */
$role=$_SESSION['role']; $userId=(int)$_SESSION['user_id']; $page=(string)($_GET['page']??'dashboard');
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_student'])) {
    $reg=trim((string)($_POST['reg_number']??'')); $name=trim((string)($_POST['full_name']??''));
    $phone=phone_normalize((string)($_POST['phone_number']??'')); $department=trim((string)($_POST['department']??$_SESSION['department']));
    $class=trim((string)($_POST['class_level']??'Year 4')); $pass=(string)($_POST['student_password']??'');
    if ($reg===''||$name===''||$phone===''||$pass==='') $error='Fill in all required student fields.';
    elseif (strlen($pass)<6) $error='Student password must be at least 6 characters.';
    else {
        $hash=password_hash($pass,PASSWORD_DEFAULT);
        $q=$conn->prepare('INSERT INTO students(reg_number,full_name,phone_number,department,class_level,password_hash) VALUES(?,?,?,?,?,?)');
        $q->bind_param('ssssss',$reg,$name,$phone,$department,$class,$hash);
        if($q->execute()) $notice='Student registered successfully.'; else $error='Could not register student. Registration number may already exist.';
        $q->close();
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_mark'])) {
    if ($role!=='LECTURER') $error='Only lecturers can upload marks.';
    else {
        $reg=trim((string)($_POST['reg_number']??'')); $year=trim((string)($_POST['academic_year']??date('Y')));
        $semester=(int)($_POST['semester']??0); $code=trim((string)($_POST['subject_code']??''));
        $subject=trim((string)($_POST['subject_name']??'')); $mark=$_POST['marks']??'';
        $q=$conn->prepare('SELECT id FROM students WHERE reg_number=? LIMIT 1'); $q->bind_param('s',$reg); $q->execute(); $stu=$q->get_result()->fetch_assoc(); $q->close();
        if(!$stu||$year===''||!in_array($semester,[1,2],true)||$code===''||$subject===''||!is_numeric($mark)||(float)$mark<0||(float)$mark>100) $error='Check the registration number, semester, subject fields, and marks (0–100).';
        else {
            $studentId=(int)$stu['id']; $markVal=(float)$mark;
            $q=$conn->prepare("INSERT INTO marks(student_id,lecturer_id,academic_year,semester,subject_code,subject_name,marks,status) VALUES(?,?,?,?,?,?,?,'PENDING')");
            $q->bind_param('iisissd',$studentId,$userId,$year,$semester,$code,$subject,$markVal);
            if($q->execute()) $notice='Marks saved as PENDING. The HOD must approve before students can view them.';
            else $error='Could not save marks. Check for duplicate/invalid data.';
            $q->close();
        }
    }
}

/* Bulk Excel import: .xlsx/.xls requires PhpSpreadsheet; .csv is also accepted. */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['upload_excel'])) {
    if ($role !== 'LECTURER') {
        $error = 'Only lecturers can upload marks.';
    } elseif (!isset($_FILES['marks_file']) || $_FILES['marks_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Choose an Excel file to upload.';
    } else {
        $tmp = $_FILES['marks_file']['tmp_name'];
        $original = (string)($_FILES['marks_file']['name'] ?? '');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $rows = [];
        try {
            if ($ext === 'csv') {
                $fh = fopen($tmp, 'r');
                if (!$fh) throw new RuntimeException('Could not read the uploaded CSV file.');
                while (($row = fgetcsv($fh)) !== false) $rows[] = $row;
                fclose($fh);
            } elseif (in_array($ext, ['xlsx','xls'], true)) {
                $autoload = __DIR__ . '/vendor/autoload.php';
                if (!file_exists($autoload)) throw new RuntimeException('Excel .xlsx/.xls support needs PhpSpreadsheet. Run: composer require phpoffice/phpspreadsheet. You can also save the Excel sheet as CSV and upload that.');
                require_once $autoload;
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
                $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
            } else {
                throw new RuntimeException('Unsupported file type. Upload .xlsx, .xls, or .csv.');
            }
            if (count($rows) < 2) throw new RuntimeException('The file must contain a header row and at least one marks row.');
            // Normalize Excel/CSV headers: remove UTF-8 BOM, spaces, punctuation and case differences.
            $normalizeHeader = static function($value): string {
                $value = preg_replace('/^\xEF\xBB\xBF/', '', (string)$value);
                $value = strtolower(trim($value));
                return preg_replace('/[^a-z0-9]/', '', $value);
            };
            $header = array_map($normalizeHeader, array_shift($rows));
            $aliases = [
                'registration_number'=>['registrationnumber','regnumber','registrationno','regno','studentregistrationnumber','studentregno','studentid'],
                'academic_year'=>['academicyear','year','academicyearname'],
                'semester'=>['semester','sem','semesterid'],
                'subject_code'=>['subjectcode','coursecode','code','modulecode'],
                'subject_name'=>['subjectname','coursename','subject','modulename','course'],
                'marks'=>['marks','mark','score','marksobtained','scoreobtained']
            ];
            $idx = [];
            foreach ($aliases as $key=>$names) {
                foreach ($header as $i=>$h) if (in_array($h, $names, true)) { $idx[$key]=$i; break; }
                if (!isset($idx[$key])) {
                    throw new RuntimeException('Missing column: ' . $key . '. Required headers: registration_number, academic_year, semester, subject_code, subject_name, marks. Your detected headers are: ' . implode(', ', $header));
                }
            }
            $inserted = 0; $skipped = [];
            $conn->begin_transaction();
            foreach ($rows as $line=>$row) {
                if (!array_filter($row, static fn($v)=>trim((string)$v)!=='')) continue;
                $reg=trim((string)($row[$idx['registration_number']]??''));
                $year=trim((string)($row[$idx['academic_year']]??''));
                $semester=(int)($row[$idx['semester']]??0);
                $code=trim((string)($row[$idx['subject_code']]??''));
                $subject=trim((string)($row[$idx['subject_name']]??''));
                $mark=$row[$idx['marks']]??null;
                $q=$conn->prepare('SELECT id,department FROM students WHERE reg_number=? LIMIT 1');
                $q->bind_param('s',$reg); $q->execute(); $stu=$q->get_result()->fetch_assoc(); $q->close();
                if (!$stu || $stu['department'] !== $_SESSION['department'] || $year==='' || !in_array($semester,[1,2],true) || $code==='' || $subject==='' || !is_numeric($mark) || (float)$mark<0 || (float)$mark>100) {
                    $skipped[]='Row '.($line+2).': invalid data, unknown student, or student belongs to another department.'; continue;
                }
                $sid=(int)$stu['id']; $markVal=(float)$mark;
                $q=$conn->prepare("INSERT INTO marks(student_id,lecturer_id,academic_year,semester,subject_code,subject_name,marks,status) VALUES(?,?,?,?,?,?,?,'PENDING')");
                $q->bind_param('iisissd',$sid,$userId,$year,$semester,$code,$subject,$markVal);
                if ($q->execute()) $inserted++; else $skipped[]='Row '.($line+2).': could not save; check for duplicate marks.';
                $q->close();
            }
            $conn->commit();
            $notice="Excel import finished: {$inserted} marks saved as PENDING.";
            if ($skipped) $notice .= ' Skipped '.count($skipped).' row(s). '.implode(' ', array_slice($skipped,0,5));
        } catch (Throwable $ex) {
            if ($conn->errno === 0) { /* transaction may not have started */ }
            try { $conn->rollback(); } catch (Throwable $ignore) {}
            $error = 'Excel upload failed: '.$ex->getMessage();
        }
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['approve_all'])) {
    if($role!=='HOD') $error='Only the HOD can approve marks.';
    else {
        $semester=(int)($_POST['approve_semester']??0); $year=trim((string)($_POST['approve_year']??date('Y')));
        if(!in_array($semester,[1,2],true)||$year==='') $error='Select a valid academic year and semester.';
        else {
            $conn->begin_transaction();
            $q=$conn->prepare("UPDATE marks SET status='PUBLISHED', approved_by=?, approved_at=NOW() WHERE status='PENDING' AND semester=? AND academic_year=?");
            $q->bind_param('iis',$userId,$semester,$year);
            $ok=$q->execute(); $approved=$q->affected_rows; $q->close();
            if($ok) {
                $conn->commit();
                $notice="Approved and published {$approved} mark record(s) for {$year}, Semester {$semester}.";
                // Send one notification per student for this publication batch.
                if($approved>0) {
                    $batch=$year.'-S'.$semester;
                    $q=$conn->prepare("SELECT DISTINCT s.id,s.full_name,s.phone_number FROM students s JOIN marks m ON m.student_id=s.id WHERE m.status='PUBLISHED' AND m.academic_year=? AND m.semester=? AND m.approved_by=?");
                    $q->bind_param('sii',$year,$semester,$userId); $q->execute(); $students=$q->get_result();
                    while($st=$students->fetch_assoc()) {
                        $studentId=(int)$st['id']; $phone=$st['phone_number'];
                        $check=$conn->prepare('SELECT id FROM notification_log WHERE student_id=? AND mark_batch=? LIMIT 1');
                        $check->bind_param('is',$studentId,$batch); $check->execute(); $logged=$check->get_result()->fetch_assoc(); $check->close();
                        if($logged) continue;
                        $smsText='Final Year Project: Dear '.$st['full_name'].', your '.$year.' Semester '.$semester.' marks are published. Dial '.USSD_CODE.' and enter your registration number and password to view them.';
                        $sms=send_sms($phone,$smsText); $status=$sms['status']; $response=substr($sms['response'],0,4000);
                        $log=$conn->prepare('INSERT INTO notification_log(student_id,mark_batch,phone_number,message_text,delivery_status,provider_response) VALUES(?,?,?,?,?,?)');
                        $log->bind_param('isssss',$studentId,$batch,$phone,$smsText,$status,$response); $log->execute(); $log->close();
                    }
                    $q->close();
                    $notice.=' SMS notification processing finished. Configure Africa\'s Talking credentials to send real SMS; check Notification Log for status.';
                }
            } else { $conn->rollback(); $error='Approval failed. Please try again.'; }
        }
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['change_password'])) {
    $old=(string)($_POST['old_password']??''); $new=(string)($_POST['new_password']??'');
    if(strlen($new)<8) $error='New password must contain at least 8 characters.';
    else {
        $q=$conn->prepare('SELECT password_hash FROM users WHERE id=?'); $q->bind_param('i',$userId); $q->execute(); $row=$q->get_result()->fetch_assoc(); $q->close();
        if(!$row||!password_verify($old,$row['password_hash'])) $error='Current password is incorrect.';
        else { $hash=password_hash($new,PASSWORD_DEFAULT); $q=$conn->prepare('UPDATE users SET password_hash=? WHERE id=?'); $q->bind_param('si',$hash,$userId); $q->execute(); $q->close(); $notice='Password changed.'; }
    }
}

function count_query(mysqli $conn,string $sql): int { $r=$conn->query($sql); return $r?(int)($r->fetch_row()[0]??0):0; }
$studentCount=count_query($conn,'SELECT COUNT(*) FROM students');
$pendingCount=count_query($conn,"SELECT COUNT(*) FROM marks WHERE status='PENDING'");
$publishedCount=count_query($conn,"SELECT COUNT(*) FROM marks WHERE status='PUBLISHED'");
$students=[]; $marks=[]; $notifications=[];
if($page==='students') { $r=$conn->query('SELECT reg_number,full_name,phone_number,department,class_level,created_at FROM students ORDER BY full_name'); while($r&&$x=$r->fetch_assoc())$students[]=$x; }
if($page==='marks' || $page==='approve') {
    $sql='SELECT m.id,s.reg_number,s.full_name,m.academic_year,m.semester,m.subject_code,m.subject_name,m.marks,m.status,u.username AS lecturer FROM marks m JOIN students s ON s.id=m.student_id JOIN users u ON u.id=m.lecturer_id ORDER BY m.created_at DESC LIMIT 500';
    $r=$conn->query($sql); while($r&&$x=$r->fetch_assoc())$marks[]=$x;
}
if($page==='notifications') { $r=$conn->query('SELECT n.*,s.reg_number,s.full_name FROM notification_log n JOIN students s ON s.id=n.student_id ORDER BY n.id DESC LIMIT 200'); while($r&&$x=$r->fetch_assoc())$notifications[]=$x; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Final Year Project</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:#f3f6fb;color:#182334}.sidebar{position:fixed;inset:0 auto 0 0;width:230px;background:#173f70;color:#fff;padding:22px 16px;overflow:auto}.brand{font-size:20px;font-weight:bold;margin:5px 8px 24px}.sidebar a{display:block;padding:12px 13px;margin:5px 0;color:white;text-decoration:none;border-radius:7px}.sidebar a:hover,.sidebar a.active{background:#0e2c50}.main{margin-left:230px;padding:26px;max-width:1600px}.top{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;background:white;padding:18px 22px;border-radius:12px;margin-bottom:20px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:15px;margin-bottom:20px}.card,.panel{background:white;padding:20px;border-radius:12px;box-shadow:0 3px 14px #193a5c0b;margin-bottom:18px}.num{font-size:30px;font-weight:bold;color:#174a7e}.formgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}label{display:block;font-weight:bold;margin:10px 0 6px}input,select{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:7px}button,.btn{display:inline-block;border:0;border-radius:7px;padding:11px 15px;background:#174a7e;color:white;text-decoration:none;cursor:pointer;margin-top:12px}button.green{background:#16803c}.notice{padding:13px;background:#dcfce7;color:#166534;border-radius:8px;margin-bottom:14px}.error{padding:13px;background:#fee2e2;color:#991b1b;border-radius:8px;margin-bottom:14px}.tablewrap{overflow:auto}table{border-collapse:collapse;width:100%;background:white}th,td{text-align:left;padding:10px;border-bottom:1px solid #e2e8f0;white-space:nowrap}th{background:#edf3fa}.tag{font-weight:bold;color:#9a6700}.published{color:#16803c;font-weight:bold}.muted{color:#64748b;font-size:13px}.ussd{background:#eff6ff;padding:15px;border-radius:9px;line-height:1.7}@media(max-width:800px){.sidebar{position:static;width:auto}.main{margin:0;padding:14px}}
</style></head><body>
<aside class="sidebar"><div class="brand">FINAL YEAR PROJECT</div>
<a class="<?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard">Dashboard</a>
<a class="<?= $page==='students'?'active':'' ?>" href="?page=students">Students</a>
<?php if($role==='LECTURER'): ?><a class="<?= $page==='upload'?'active':'' ?>" href="?page=upload">Upload Marks</a><?php endif; ?>
<?php if($role==='HOD'): ?><a class="<?= $page==='approve'?'active':'' ?>" href="?page=approve">Approve / Publish</a><?php endif; ?>
<a class="<?= $page==='marks'?'active':'' ?>" href="?page=marks">Marks Records</a>
<a class="<?= $page==='notifications'?'active':'' ?>" href="?page=notifications">Notification Log</a>
<a class="<?= $page==='password'?'active':'' ?>" href="?page=password">Change Password</a><a href="?logout=1">Logout</a>
</aside><main class="main"><header class="top"><div><h2 style="margin:0">Student Marks Management</h2><span class="muted">Signed in as <?=e($_SESSION['username'])?> (<?=e($role)?>)</span></div><span><?=date('d M Y')?></span></header>
<?php if($notice!==''): ?><div class="notice"><?=e($notice)?></div><?php endif; ?><?php if($error!==''): ?><div class="error"><?=e($error)?></div><?php endif; ?>
<?php if($page==='dashboard'): ?>
<div class="cards"><div class="card">Registered students<div class="num"><?=$studentCount?></div></div><div class="card">Pending marks<div class="num"><?=$pendingCount?></div></div><div class="card">Published marks<div class="num"><?=$publishedCount?></div></div></div>
<div class="panel"><h3>Workflow</h3><ol><li>Register students and give each a registration number and password.</li><li>Lecturers upload marks; all new marks remain <b>PENDING</b>.</li><li>The HOD selects an academic year and semester, then approves all pending marks with one action.</li><li>After approval, students' marks become available via USSD and notification SMS is attempted.</li></ol></div>
<div class="ussd"><b>USSD access</b><br>Configure your Africa's Talking shortcode in <code>USSD_CODE</code>, set the callback URL to this file, then students dial the shortcode, choose results, enter registration number, password, and semester.</div>
<?php elseif($page==='students'): ?>
<div class="panel"><h3>Register Student</h3><form method="post"><div class="formgrid">
<div><label>Registration number *</label><input name="reg_number" required></div><div><label>Full name *</label><input name="full_name" required></div><div><label>Phone number *</label><input name="phone_number" placeholder="+2507XXXXXXXX or 07XXXXXXXX" required></div><div><label>Department</label><input name="department" value="<?=e($_SESSION['department'])?>" required></div><div><label>Class / Year</label><input name="class_level" value="Year 4" required></div><div><label>Initial student password *</label><input type="password" name="student_password" minlength="6" required></div></div><button name="add_student" value="1">Register Student</button></form></div>
<div class="panel"><h3>Registered Students</h3><div class="tablewrap"><table><tr><th>Reg. number</th><th>Name</th><th>Phone</th><th>Department</th><th>Class</th></tr><?php foreach($students as $s): ?><tr><td><?=e($s['reg_number'])?></td><td><?=e($s['full_name'])?></td><td><?=e($s['phone_number'])?></td><td><?=e($s['department'])?></td><td><?=e($s['class_level'])?></td></tr><?php endforeach; ?></table></div></div>
<?php elseif($page==='upload' && $role==='LECTURER'): ?>
<div class="panel"><h3>Lecturer: Upload Marks from Excel</h3><p>Upload .xlsx/.xls (after installing PhpSpreadsheet) or a CSV file exported from Excel. Every valid row is saved as <b>PENDING</b> for HOD approval.</p><form method="post" enctype="multipart/form-data"><label>Excel / CSV file</label><input type="file" name="marks_file" accept=".xlsx,.xls,.csv" required><button name="upload_excel" value="1">Upload Marks File</button></form><p class="muted">Required header columns: registration_number, academic_year, semester, subject_code, subject_name, marks. Student registration numbers must already be registered in the same department.</p><p><a class="btn" href="?page=template">View Excel template format</a></p></div><div class="panel"><h3>Or Enter One Student's Marks Manually</h3><form method="post"><div class="formgrid">
<div><label>Student registration number *</label><input name="reg_number" required></div><div><label>Academic year *</label><input name="academic_year" value="<?=date('Y')?>" required></div><div><label>Semester *</label><select name="semester" required><option value="1">Semester 1</option><option value="2">Semester 2</option></select></div><div><label>Subject code *</label><input name="subject_code" required></div><div><label>Subject name *</label><input name="subject_name" required></div><div><label>Marks (0–100) *</label><input name="marks" type="number" min="0" max="100" step="0.01" required></div></div><button name="add_mark" value="1">Save Marks as Pending</button></form><p class="muted">A student can only view marks after the HOD approves them.</p></div>
<?php elseif($page==='approve' && $role==='HOD'): ?>
<div class="panel"><h3>HOD: Approve All Pending Marks</h3><p>Review the pending records below. Approval publishes every pending mark for the selected academic year and semester in one action.</p><form method="post" onsubmit="return confirm('Approve and publish all pending marks for this year and semester? This makes them visible to students and starts SMS notifications.');"><div class="formgrid"><div><label>Academic year</label><input name="approve_year" value="<?=date('Y')?>" required></div><div><label>Semester</label><select name="approve_semester"><option value="1">Semester 1</option><option value="2">Semester 2</option></select></div></div><button class="green" name="approve_all" value="1">Select All Pending &amp; Approve Once</button></form></div>
<div class="panel"><h3>Marks Review</h3><div class="tablewrap"><table><tr><th>Reg.</th><th>Student</th><th>Year</th><th>Sem.</th><th>Code</th><th>Subject</th><th>Marks</th><th>Status</th><th>Lecturer</th></tr><?php foreach($marks as $m): ?><tr><td><?=e($m['reg_number'])?></td><td><?=e($m['full_name'])?></td><td><?=e($m['academic_year'])?></td><td><?=e($m['semester'])?></td><td><?=e($m['subject_code'])?></td><td><?=e($m['subject_name'])?></td><td><?=e($m['marks'])?></td><td class="<?=$m['status']==='PUBLISHED'?'published':'tag'?>"><?=e($m['status'])?></td><td><?=e($m['lecturer'])?></td></tr><?php endforeach; ?></table></div></div>
<?php elseif($page==='marks' || $page==='approve'): ?>
<div class="panel"><h3>Marks Records</h3><div class="tablewrap"><table><tr><th>Reg.</th><th>Student</th><th>Year</th><th>Semester</th><th>Code</th><th>Subject</th><th>Marks</th><th>Status</th><th>Lecturer</th></tr><?php foreach($marks as $m): ?><tr><td><?=e($m['reg_number'])?></td><td><?=e($m['full_name'])?></td><td><?=e($m['academic_year'])?></td><td><?=e($m['semester'])?></td><td><?=e($m['subject_code'])?></td><td><?=e($m['subject_name'])?></td><td><?=e($m['marks'])?></td><td class="<?=$m['status']==='PUBLISHED'?'published':'tag'?>"><?=e($m['status'])?></td><td><?=e($m['lecturer'])?></td></tr><?php endforeach; ?></table></div></div>
<?php elseif($page==='notifications'): ?>
<div class="panel"><h3>SMS Notification Log</h3><div class="tablewrap"><table><tr><th>Reg.</th><th>Student</th><th>Phone</th><th>Batch</th><th>Status</th><th>Message</th><th>Date</th></tr><?php foreach($notifications as $n): ?><tr><td><?=e($n['reg_number'])?></td><td><?=e($n['full_name'])?></td><td><?=e($n['phone_number'])?></td><td><?=e($n['mark_batch'])?></td><td><?=e($n['delivery_status'])?></td><td><?=e($n['message_text'])?></td><td><?=e($n['created_at'])?></td></tr><?php endforeach; ?></table></div></div>
<?php elseif($page==='template'): ?>
<div class="panel"><h3>Excel Marks Template</h3><p>Create an Excel workbook with these exact columns in row 1, then add one student per row.</p><div class="tablewrap"><table><tr><th>registration_number</th><th>academic_year</th><th>semester</th><th>subject_code</th><th>subject_name</th><th>marks</th></tr><tr><td>RP-2026-001</td><td>2026</td><td>1</td><td>IT101</td><td>Database Systems</td><td>85</td></tr><tr><td>RP-2026-002</td><td>2026</td><td>1</td><td>IT101</td><td>Database Systems</td><td>78.5</td></tr></table></div><p class="muted">The sample registration numbers are examples only. Replace them with real registered student numbers. Save as .xlsx or CSV.</p><a class="btn" href="?page=upload">Back to Upload Marks</a></div>
<?php elseif($page==='password'): ?>
<div class="panel"><h3>Change Login Password</h3><form method="post"><div class="formgrid"><div><label>Current password</label><input type="password" name="old_password" required></div><div><label>New password (at least 8 characters)</label><input type="password" name="new_password" minlength="8" required></div></div><button name="change_password" value="1">Change Password</button></form></div>
<?php else: ?><div class="panel"><h3>Page not found</h3><a href="?page=dashboard">Return to dashboard</a></div><?php endif; ?>
<footer class="muted">Final Year Project · Student Marks Publication System · <?=date('Y')?></footer></main></body></html>
