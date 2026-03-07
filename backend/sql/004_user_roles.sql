ALTER TABLE users
  MODIFY COLUMN role ENUM('admin', 'project_manager', 'onsite_user', 'user')
  NOT NULL DEFAULT 'project_manager';
