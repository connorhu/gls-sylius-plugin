<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A `gls_parcel` tábla (#257). PostgreSQL-re írva (a Sylius-bolt adatbázisa);
 * a `skipIf` miatt egy olyan boltban is lefut, ahol a tábla már megvan.
 */
final class Version20261112000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'GLS: a gls_parcel tábla.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('gls_parcel'), 'A gls_parcel tábla már létezik.');

        $this->addSql('CREATE TABLE gls_parcel (
            id SERIAL NOT NULL,
            shipment_id INT NOT NULL,
            order_number VARCHAR(30) NOT NULL,
            delivery_kind VARCHAR(32) NOT NULL,
            manual BOOLEAN DEFAULT FALSE NOT NULL,
            parcel_id BIGINT DEFAULT NULL,
            parcel_number VARCHAR(30) DEFAULT NULL,
            parcel_count SMALLINT DEFAULT 1 NOT NULL,
            weight NUMERIC(7, 2) DEFAULT NULL,
            recipient_name VARCHAR(150) DEFAULT \'\' NOT NULL,
            recipient_phone VARCHAR(30) DEFAULT NULL,
            recipient_email VARCHAR(150) DEFAULT NULL,
            pickup_point_id VARCHAR(50) DEFAULT NULL,
            zip_code VARCHAR(10) DEFAULT NULL,
            city VARCHAR(60) DEFAULT NULL,
            street VARCHAR(150) DEFAULT NULL,
            house_number VARCHAR(10) DEFAULT NULL,
            house_number_info VARCHAR(50) DEFAULT NULL,
            country_code VARCHAR(2) DEFAULT \'HU\' NOT NULL,
            cod_amount INT DEFAULT NULL,
            internal_status VARCHAR(40) NOT NULL,
            gls_status VARCHAR(20) DEFAULT NULL,
            last_api_error TEXT DEFAULT NULL,
            last_api_error_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            retry_count SMALLINT DEFAULT 0 NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            registered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            label_generated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            handed_over_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E5FDF6217BE036FC ON gls_parcel (shipment_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E5FDF621465E670C ON gls_parcel (parcel_id)');
        $this->addSql('CREATE INDEX idx_gls_parcel_status ON gls_parcel (internal_status)');
        $this->addSql('CREATE INDEX idx_gls_parcel_created ON gls_parcel (created_at)');
        $this->addSql('ALTER TABLE gls_parcel ADD CONSTRAINT FK_gls_parcel_shipment
            FOREIGN KEY (shipment_id) REFERENCES sylius_shipment (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('gls_parcel'), 'A gls_parcel tábla nincs meg.');

        $this->addSql('DROP TABLE gls_parcel');
    }
}
