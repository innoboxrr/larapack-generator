<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las invariantes del IMSS por ramo y del timbrado de los recibos (Oleada 9d · L3952, L3954).
 *
 * LAS COLUMNAS, A SU ANCHO. El contrato no sabe declarar precisión ni longitud: el generador deja `DECIMAL(8,2)` y `VARCHAR(255)`, y
 * un `default(01)` se vuelve el entero 1. `MODIFY` reemplaza la definición ENTERA de la columna, así que cada una repite su
 * nulabilidad y su `DEFAULT`.
 *
 * UNA CUOTA VIGENTE POR ESCUELA (`active_marker` STORED, vale 1 mientras la fila es ACTIVE y NULL después, con un índice único).
 * UNA FACTURA VIVA POR RECIBO (`live_entry_id` STORED: el recibo mientras la factura está pedida o timbrada, NULL si falló o se
 * canceló): pedir dos veces el mismo recibo no timbra dos veces, y lo sostiene la base. UN PERFIL FISCAL POR PERSONA Y ESCUELA.
 *
 * Y los CHECK que cambian con las clases nuevas: `OVERTIME` entre las percepciones y `IMSS_BRANCHES` entre las deducciones.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private const CHECKS = [
        'payroll_social_security_settings' => [
            'ck_payroll_imss_status' => "`status` IN ('ACTIVE','RETIRED')",
            'ck_payroll_imss_values' => '`uma_daily_amount` > 0 AND `integration_factor` >= 1 AND `integration_factor` <= 2',
        ],
        'payroll_payroll_fiscal_profiles' => [
            'ck_payroll_fiscal_sbc' => '`sbc_daily_amount` IS NULL OR `sbc_daily_amount` > 0',
        ],
        'payroll_payslip_invoices' => [
            'ck_payroll_invoice_status' => "`status` IN ('REQUESTED','STAMPED','FAILED','CANCELLED')",
            'ck_payroll_invoice_stamped' => "`status` <> 'STAMPED' OR `fiscal_uuid` IS NOT NULL",
            'ck_payroll_invoice_total' => '`total` >= 0',
        ],
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE `payroll_social_security_settings` MODIFY `uma_daily_amount` DECIMAL(14,4) NOT NULL');
        DB::statement('ALTER TABLE `payroll_social_security_settings` MODIFY `integration_factor` DECIMAL(9,4) NOT NULL');
        DB::statement('ALTER TABLE `payroll_social_security_settings` ADD COLUMN `active_marker` TINYINT UNSIGNED '
            ."GENERATED ALWAYS AS (IF(`status` = 'ACTIVE', 1, NULL)) STORED");
        DB::statement('ALTER TABLE `payroll_social_security_settings` ADD UNIQUE INDEX `payroll_imss_one_active_uq` (`scope_id`, `active_marker`)');

        DB::statement('ALTER TABLE `payroll_payroll_fiscal_profiles` MODIFY `rfc` VARCHAR(13) NOT NULL');
        DB::statement('ALTER TABLE `payroll_payroll_fiscal_profiles` MODIFY `curp` VARCHAR(18) NOT NULL');
        DB::statement('ALTER TABLE `payroll_payroll_fiscal_profiles` MODIFY `nss` VARCHAR(11) NULL');
        DB::statement('ALTER TABLE `payroll_payroll_fiscal_profiles` MODIFY `postal_code` VARCHAR(5) NOT NULL');
        DB::statement('ALTER TABLE `payroll_payroll_fiscal_profiles` MODIFY `state_key` VARCHAR(3) NOT NULL');
        DB::statement("ALTER TABLE `payroll_payroll_fiscal_profiles` MODIFY `contract_type` VARCHAR(2) NOT NULL DEFAULT '01'");
        DB::statement("ALTER TABLE `payroll_payroll_fiscal_profiles` MODIFY `regime_type` VARCHAR(2) NOT NULL DEFAULT '02'");
        DB::statement('ALTER TABLE `payroll_payroll_fiscal_profiles` MODIFY `risk_class` VARCHAR(1) NULL');
        DB::statement('ALTER TABLE `payroll_payroll_fiscal_profiles` MODIFY `sbc_daily_amount` DECIMAL(14,4) NULL');
        DB::statement('ALTER TABLE `payroll_payroll_fiscal_profiles` ADD UNIQUE INDEX `payroll_fiscal_person_uq` (`scope_id`, `party_id`)');

        DB::statement('ALTER TABLE `payroll_payslip_invoices` MODIFY `total` DECIMAL(14,4) NOT NULL');
        DB::statement('ALTER TABLE `payroll_payslip_invoices` ADD UNIQUE INDEX `payroll_invoice_uuid_uq` (`uuid`)');
        DB::statement('ALTER TABLE `payroll_payslip_invoices` ADD COLUMN `live_entry_id` BIGINT UNSIGNED '
            ."GENERATED ALWAYS AS (IF(`status` IN ('REQUESTED','STAMPED'), `payroll_entry_id`, NULL)) STORED");
        DB::statement('ALTER TABLE `payroll_payslip_invoices` ADD UNIQUE INDEX `payroll_invoice_one_live_uq` (`live_entry_id`)');

        // Las clases nuevas de las tablas que ya existían: se reemplaza el CHECK, no se suma otro.
        DB::statement('ALTER TABLE `payroll_earning_types` DROP CONSTRAINT `ck_payroll_earning_kind`');
        DB::statement("ALTER TABLE `payroll_earning_types` ADD CONSTRAINT `ck_payroll_earning_kind` CHECK (`kind` IN ('SALARY','OVERTIME','OTHER'))");
        DB::statement('ALTER TABLE `payroll_deduction_types` DROP CONSTRAINT `ck_payroll_deduction_calc`');
        DB::statement("ALTER TABLE `payroll_deduction_types` ADD CONSTRAINT `ck_payroll_deduction_calc` CHECK (`calculation` IN ('TAX_TABLE','PERCENT_OF_BASE','IMSS_BRANCHES','MANUAL'))");
        // La cuota por ramo es de seguridad social, y la tabla no la lleva en el porcentaje.
        DB::statement("ALTER TABLE `payroll_deduction_types` ADD CONSTRAINT `ck_payroll_deduction_imss` CHECK (`calculation` <> 'IMSS_BRANCHES' OR (`kind` = 'SOCIAL_SECURITY' AND `rate_percent` IS NULL))");

        // Los días del recibo: trabajados no pasan de los del periodo.
        DB::statement('ALTER TABLE `payroll_payroll_entries` ADD CONSTRAINT `ck_payroll_entry_days` CHECK (`worked_days` IS NULL OR `period_days` IS NULL OR `worked_days` <= `period_days`)');

        foreach (self::CHECKS as $table => $checks) {
            foreach ($checks as $name => $expression) {
                DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', $table, $name, $expression));
            }
        }
    }

    public function down(): void
    {
        foreach (self::CHECKS as $table => $checks) {
            foreach (array_keys($checks) as $name) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP CONSTRAINT `%s`', $table, $name));
            }
        }

        DB::statement('ALTER TABLE `payroll_payroll_entries` DROP CONSTRAINT `ck_payroll_entry_days`');
        DB::statement('ALTER TABLE `payroll_deduction_types` DROP CONSTRAINT `ck_payroll_deduction_imss`');
        DB::statement('ALTER TABLE `payroll_deduction_types` DROP CONSTRAINT `ck_payroll_deduction_calc`');
        DB::statement("ALTER TABLE `payroll_deduction_types` ADD CONSTRAINT `ck_payroll_deduction_calc` CHECK (`calculation` IN ('TAX_TABLE','PERCENT_OF_BASE','MANUAL'))");
        DB::statement('ALTER TABLE `payroll_earning_types` DROP CONSTRAINT `ck_payroll_earning_kind`');
        DB::statement("ALTER TABLE `payroll_earning_types` ADD CONSTRAINT `ck_payroll_earning_kind` CHECK (`kind` IN ('SALARY','OTHER'))");

        // Las claves ajenas necesitan un índice propio antes de soltar el único que las cubría.
        DB::statement('ALTER TABLE `payroll_payslip_invoices` ADD INDEX `payroll_invoice_entry_ix` (`payroll_entry_id`)');
        DB::statement('ALTER TABLE `payroll_payslip_invoices` DROP INDEX `payroll_invoice_one_live_uq`');
        DB::statement('ALTER TABLE `payroll_payslip_invoices` DROP COLUMN `live_entry_id`');
        DB::statement('ALTER TABLE `payroll_payslip_invoices` DROP INDEX `payroll_invoice_uuid_uq`');

        DB::statement('ALTER TABLE `payroll_payroll_fiscal_profiles` ADD INDEX `payroll_fiscal_scope_ix` (`scope_id`)');
        DB::statement('ALTER TABLE `payroll_payroll_fiscal_profiles` ADD INDEX `payroll_fiscal_party_ix` (`party_id`)');
        DB::statement('ALTER TABLE `payroll_payroll_fiscal_profiles` DROP INDEX `payroll_fiscal_person_uq`');

        DB::statement('ALTER TABLE `payroll_social_security_settings` ADD INDEX `payroll_imss_scope_ix` (`scope_id`)');
        DB::statement('ALTER TABLE `payroll_social_security_settings` DROP INDEX `payroll_imss_one_active_uq`');
        DB::statement('ALTER TABLE `payroll_social_security_settings` DROP COLUMN `active_marker`');
    }
};
