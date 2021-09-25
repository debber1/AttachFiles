CREATE TABLE /*_*/attachfiles_attached (
  pageid INT NOT NULL DEFAULT 0,
  filename VARCHAR(255) NOT NULL DEFAULT '',
  displayname VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY(pageid, filename)
);
