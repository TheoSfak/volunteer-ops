#!/bin/bash
# VolunteerOps - Automated Deployment Script for yphresies.gr
# Version: 2.0
# Usage: bash deploy.sh

set -e  # Exit on error

echo "======================================"
echo "VolunteerOps Deployment Script"
echo "======================================"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
INSTALL_DIR="/home/USERNAME/public_html/volunteerops"
BACKUP_DIR="/home/USERNAME/volunteerops_backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

echo -e "${YELLOW}Step 1: Pre-deployment Checks${NC}"
echo "================================"

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo -e "${RED}Error: PHP is not installed or not in PATH${NC}"
    exit 1
fi

PHP_VERSION=$(php -r "echo PHP_VERSION;")
echo -e "${GREEN}✓ PHP Version: $PHP_VERSION${NC}"

# Check PHP extensions
echo "Checking required PHP extensions..."
php -m | grep -q "PDO" && echo -e "${GREEN}✓ PDO${NC}" || echo -e "${RED}✗ PDO (REQUIRED)${NC}"
php -m | grep -q "pdo_mysql" && echo -e "${GREEN}✓ pdo_mysql${NC}" || echo -e "${RED}✗ pdo_mysql (REQUIRED)${NC}"
php -m | grep -q "mbstring" && echo -e "${GREEN}✓ mbstring${NC}" || echo -e "${RED}✗ mbstring (REQUIRED)${NC}"

echo ""
echo -e "${YELLOW}Step 2: Backup Existing Installation${NC}"
echo "======================================="

if [ -d "$INSTALL_DIR" ]; then
    echo "Creating backup..."
    mkdir -p "$BACKUP_DIR"
    tar -czf "$BACKUP_DIR/volunteerops_backup_$TIMESTAMP.tar.gz" -C "$INSTALL_DIR" .
    echo -e "${GREEN}✓ Backup created: $BACKUP_DIR/volunteerops_backup_$TIMESTAMP.tar.gz${NC}"
else
    echo "No existing installation found. Proceeding with fresh install."
fi

echo ""
echo -e "${YELLOW}Step 3: Deploy Files${NC}"
echo "====================="

# Create installation directory
mkdir -p "$INSTALL_DIR"

# Copy files (assuming script is run from package directory)
echo "Copying files..."
cp -r ./volunteerops/* "$INSTALL_DIR/"
echo -e "${GREEN}✓ Files deployed${NC}"

echo ""
echo -e "${YELLOW}Step 4: Set Permissions${NC}"
echo "========================"

# Set proper permissions
chmod -R 755 "$INSTALL_DIR"
chmod -R 777 "$INSTALL_DIR/uploads"
chmod -R 777 "$INSTALL_DIR/backups"

echo -e "${GREEN}✓ Permissions set${NC}"
echo "  - Application files: 755"
echo "  - Uploads directory: 777"
echo "  - Backups directory: 777"

echo ""
echo -e "${YELLOW}Step 5: Database Setup${NC}"
echo "======================="

read -p "Run automated database setup? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo "Please provide database credentials:"
    read -p "Database Host [localhost]: " DB_HOST
    DB_HOST=${DB_HOST:-localhost}
    
    read -p "Database Name: " DB_NAME
    read -p "Database User: " DB_USER
    read -sp "Database Password: " DB_PASS
    echo ""
    
    # Test database connection
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1;" &> /dev/null
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ Database connection successful${NC}"
        
        # Import schema
        echo "Importing database schema..."
        mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$INSTALL_DIR/sql/schema.sql"
        echo -e "${GREEN}✓ Schema imported${NC}"
        
        # Add indexes
        echo "Adding performance indexes..."
        mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$INSTALL_DIR/sql/add_indexes.sql"
        echo -e "${GREEN}✓ Indexes created${NC}"
        
    else
        echo -e "${RED}✗ Database connection failed${NC}"
        echo "Please check credentials and try manual setup"
    fi
else
    echo "Skipping automated database setup."
    echo "Please run the web installer at: https://yphresies.gr/volunteerops/install.php"
fi

echo ""
echo -e "${YELLOW}Step 6: Security Cleanup${NC}"
echo "========================="

echo "Cleaning up installation files..."
rm -f "$INSTALL_DIR/install.php"
rm -f "$INSTALL_DIR/add_indexes.php"
rm -rf "$INSTALL_DIR/sql"
rm -f "$INSTALL_DIR/test_*.php"
rm -f "$INSTALL_DIR/demo_data.sql"
rm -f "$INSTALL_DIR/*.sql"

echo -e "${GREEN}✓ Installation files removed${NC}"

echo ""
echo -e "${GREEN}======================================"
echo "Deployment Complete!"
echo "======================================${NC}"
echo ""
echo "Next Steps:"
echo "1. Navigate to: https://yphresies.gr/volunteerops/"
echo "2. If you skipped database setup, run: https://yphresies.gr/volunteerops/install.php"
echo "3. Login with your admin credentials"
echo "4. Configure settings (SMTP, organization details)"
echo "5. Setup cron jobs for automated notifications"
echo ""
echo "Backup Location: $BACKUP_DIR/volunteerops_backup_$TIMESTAMP.tar.gz"
echo ""
echo -e "${YELLOW}Important: Update USERNAME in cron job paths!${NC}"
echo ""
