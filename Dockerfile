# השתמש בתמונה של PHP עם Apache
FROM php:8.0-apache

# העתקת הקבצים שלך לתוך התמונה
COPY . /var/www/html/

# חשיפת הפורט
EXPOSE 80

# הגדרת הפקודה להפעלת Apache
CMD ["apache2-foreground"]
