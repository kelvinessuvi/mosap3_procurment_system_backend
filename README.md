# Procurement System Backend

Backend API for the Procurement System, built with Laravel 10.

## Overview
This system works as a RESTful API to manage:
- **Authentication**: User login/logout with Sanctum.
- **Suppliers**: Management of suppliers, including document uploads (Certificates, NIF).
- **Quotations**: Requesting and evaluating quotations.
- **Acquisitions**: Generating acquisition orders.

## Setup Instructions

### Prerequisites
- PHP >= 8.1
- Composer
- SQLite (for testing) or MySQL (for production)

### Installation
1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd mosap3_procurment_system_backend
   ```

2. **Install Dependencies**
   ```bash
   composer install
   ```

3. **Environment Configuration**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   *Configure your DB settings in `.env`.*

4. **Database Migration & Seeding**
   ```bash
   php artisan migrate --seed
   ```

## Testing
The project includes a suite of Feature tests ensuring API stability.
To run tests using the in-memory SQLite database:
```bash
php artisan test
```

## API Documentation
This project uses `dedoc/scramble` to automatically generate API documentation.

1. **Access Documentation**
   Start the local server:
   ```bash
   php artisan serve
   ```
   Visit: `http://localhost:8000/docs/api`

## Security
- All API routes are protected by Sanctum middleware (except Login/Public endpoints).
- File uploads are validated for type and size.
