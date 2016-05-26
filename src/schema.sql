CREATE TABLE "releases" (
	"version_name" varchar(255) NOT NULL,
	"release_date" integer DEFAULT NULL,
	"phar_download_url" varchar(255) NOT NULL DEFAULT '',
	"signature_download_url" varchar(255) NOT NULL DEFAULT '',
	"stability" varchar(20) NOT NULL DEFAULT '',
	CONSTRAINT "PK_VersionName" PRIMARY KEY ("version_name")
);
