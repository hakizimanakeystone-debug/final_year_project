SMART MIS - Student Marks Management

FILES
- index.php: complete PHP application
- database.sql: database and table definitions

INSTALL
1. Copy the final_year_project folder into C:\xampp\htdocs\
2. Start Apache and MySQL in XAMPP.
3. Open http://localhost/phpmyadmin and import database.sql.
4. Open http://localhost/final_year_project/index.php

IMPORTANT ABOUT YOUR CURRENT URL
If you are using http://localhost/USSD_CAT/index.php, Apache is loading C:\xampp\htdocs\USSD_CAT\index.php instead. Copy the updated index.php into that exact folder and check its database settings before use. Do not keep editing a different copy.

DEMO ACCOUNTS
HOD: hod / Hod@12345
Lecturer: lecturer / Lec@12345
Both demo accounts use Information and Communication Technology (ICT). Change demo passwords before real use.

EXCEL UPLOAD
Headers can use variations such as Registration Number, registration_number, REGISTRATION_NUMBER, Reg No, Academic Year, Semester, Subject Code, Subject Name, Marks. Header matching ignores case, spaces, underscores, punctuation, and UTF-8 BOM.

For .xlsx/.xls, from the application folder run:
composer require phpoffice/phpspreadsheet
This requires Composer and compatible PHP extensions. CSV uploads do not need PhpSpreadsheet.

Expected columns:
registration_number, academic_year, semester, subject_code, subject_name, marks

Every imported mark is PENDING until HOD approval. Student registration numbers must exist and belong to the lecturer's department.

SECURITY / DEPLOYMENT
This package is a development baseline. Change demo passwords, set correct database credentials, configure Africa's Talking credentials for real SMS, and test with a backup database before production use.
