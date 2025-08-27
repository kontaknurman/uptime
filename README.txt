# Uptime Monitor

A lightweight, secure uptime monitoring application built with PHP 8+, vanilla JavaScript, and Tailwind CSS.

## Features

- **HTTP/HTTPS Monitoring**: Monitor websites and APIs with custom headers, methods, and expected responses
- **Flexible Scheduling**: Check intervals from 1 minute to 1 day
- **Smart Alerting**: Email notifications for DOWN/RECOVERY events via SMTP
- **Incident Management**: Track and visualize downtime incidents
- **Uptime Reports**: Generate CSV reports with detailed statistics
- **Responsive Dashboard**: Real-time status overview with auto-refresh
- **Security First**: CSRF protection, input validation, prepared statements

## Requirements

- PHP 8.0+ with extensions: `curl`, `openssl`, `pdo_mysql`
- MySQL 5.7+ or MariaDB 10.3+
- Web server (Apache/Nginx)

## Installation

1. **Clone or download** the application files to your web directory

2. **Create database and user**:
   ```bash
   mysql -u root -p < database.sql
   ```

3. **Configure environment** (copy and edit):
   ```bash
   cp config/config.php.example config/config.php
   ```

4. **Set permissions**:
   ```bash
   chmod 755 cli/run_checks.php
   chown www-data:www-data -R .
   ```

5. **Setup cron job** for automated checks:
   ```bash
   # Edit crontab
   crontab -e
   
   # Add this line to run checks every minute
   * * * * * /usr/bin/php /path/to/uptime-monitor/cli/run_checks.php >> /var/log/uptime-checks.log 2>&1
   ```

## Configuration

Edit `config/config.php`:

```php
return [
    'database' => [
        'host' => 'localhost',
        'name' => 'uptime_monitor',
        'user' => 'your_db_user',
        'pass' => 'your_db_password',
    ],
    
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'your_email@gmail.com',
        'password' => 'your_app_password',
        'encryption' => 'tls',
    ],
    
    'app' => [
        'timezone' => 'Asia/Jakarta',
        'base_url' => 'https://your-domain.com',
    ]
];
```

## Usage

### Default Login
- **URL**: `/login.php`
- **Email**: `admin@example.com`
- **Password**: `Admin@123`

**⚠️ Change the default password immediately after first login!**

### Adding Checks

1. Go to Dashboard → "Add Check"
2. Fill in the check details:
   - **Name**: Descriptive name for your check
   - **URL**: Target endpoint to monitor
   - **Method**: GET or POST
   - **Expected Status**: HTTP status code (default: 200)
   - **Headers**: Custom request/response headers
   - **Interval**: How often to check (1m, 5m, 1h, 1d)
   - **Alerts**: Comma-separated email addresses

### Expected Response Headers

Use these formats for response header validation:

- **Exact match**: `Content-Type: application/json`
- **Substring**: `Server: nginx` (matches any nginx version)
- **Regex**: `Content-Length: /^[0-9]+$/` (numbers only)

### Viewing Results

- **Dashboard**: Overview of all checks with status indicators
- **Check Details**: Individual check history with latency charts
- **Reports**: Generate CSV exports for specific date ranges

## Security Features

- **Authentication**: Secure login with bcrypt password hashing
- **CSRF Protection**: All forms protected against cross-site request forgery
- **Input Validation**: All user inputs sanitized and validated
- **SQL Injection Prevention**: Prepared statements throughout
- **Security Headers**: XSS protection, content type sniffing prevention

## File Structure

```
uptime-monitor/
├── bootstrap.php           # Application bootstrap
├── login.php              # Login page
├── dashboard.php          # Main dashboard
├── check.php             # Check details view
├── check_form.php        # Add/edit checks
├── reports.php           # Reports and CSV export
├── logout.php            # Logout handler
├── config/
│   └── config.php        # Configuration file
├── lib/
│   ├── Database.php      # Database abstraction
│   ├── Auth.php          # Authentication & security
│   ├── CheckRunner.php   # HTTP check execution
│   └── Emailer.php       # SMTP email functionality
├── cli/
│   └── run_checks.php    # Cron script for checks
└── database.sql          # Database schema
```

## Customization

### Adding Check Intervals

Edit the `$intervalOptions` array in `check_form.php`:

```php
$intervalOptions = [
    60 => '1 minute',
    300 => '5 minutes',
    1800 => '30 minutes',  // Add custom interval
    3600 => '1 hour',
    86400 => '1 day'
];
```

### Email Templates

Customize alert emails in `lib/CheckRunner.php`:

```php
private function sendAlert(array $check, string $type, int $incidentId): void {
    // Customize email subject and body here
    $subject = "[MyCompany] {$type}: {$check['name']}";
    $body = "Custom email template...";
}
```

## Troubleshooting

### Checks Not Running
1. Verify cron job is active: `crontab -l`
2. Check cron logs: `tail -f /var/log/uptime-checks.log`
3. Run manually: `php cli/run_checks.php`

### Email Alerts Not Sending
1. Verify SMTP configuration in `config/config.php`
2. Test SMTP connectivity from server
3. Check logs for SMTP errors

### Performance Issues
1. Clean old results: Database automatically keeps 7 days
2. Add database indexes for custom queries
3. Optimize check intervals for high-frequency monitoring

## License

This project is open source and available under the MIT License.