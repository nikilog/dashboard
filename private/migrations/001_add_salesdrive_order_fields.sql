ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `form_id` int(11) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `version` int(11) DEFAULT NULL AFTER `form_id`,
  ADD COLUMN IF NOT EXISTS `order_number` varchar(64) DEFAULT NULL AFTER `version`,
  ADD COLUMN IF NOT EXISTS `payment_date` date DEFAULT NULL AFTER `update_at`,
  ADD COLUMN IF NOT EXISTS `cost_price_amount` decimal(10,2) DEFAULT 0.00 AFTER `profit_amount`,
  ADD COLUMN IF NOT EXISTS `debt_amount` decimal(10,2) DEFAULT 0.00 AFTER `rest_pay`,
  ADD COLUMN IF NOT EXISTS `mutual_settlement_id` int(11) DEFAULT NULL AFTER `stock_id`,
  ADD COLUMN IF NOT EXISTS `promo_code_id` int(11) DEFAULT NULL AFTER `mutual_settlement_id`,
  ADD COLUMN IF NOT EXISTS `dispatch_term_id` int(11) DEFAULT NULL AFTER `promo_code_id`,
  ADD COLUMN IF NOT EXISTS `utm_page` varchar(1024) DEFAULT NULL AFTER `utm_campaign`,
  ADD COLUMN IF NOT EXISTS `utm_source_full` varchar(1024) DEFAULT NULL AFTER `utm_page`,
  ADD COLUMN IF NOT EXISTS `utm_content` varchar(255) DEFAULT NULL AFTER `utm_source_full`,
  ADD COLUMN IF NOT EXISTS `utm_term` varchar(255) DEFAULT NULL AFTER `utm_content`,
  ADD COLUMN IF NOT EXISTS `external_id` varchar(255) DEFAULT NULL AFTER `utm_term`,
  ADD COLUMN IF NOT EXISTS `delivery_provider` varchar(64) DEFAULT NULL AFTER `external_id`,
  ADD COLUMN IF NOT EXISTS `delivery_tracking_number` varchar(64) DEFAULT NULL AFTER `delivery_provider`,
  ADD COLUMN IF NOT EXISTS `delivery_status_code` int(11) DEFAULT NULL AFTER `delivery_tracking_number`,
  ADD COLUMN IF NOT EXISTS `delivery_delivered_at` datetime DEFAULT NULL AFTER `delivery_status_code`,
  ADD COLUMN IF NOT EXISTS `delivery_sender_id` int(11) DEFAULT NULL AFTER `delivery_delivered_at`,
  ADD COLUMN IF NOT EXISTS `delivery_area_name` varchar(255) DEFAULT NULL AFTER `delivery_sender_id`,
  ADD COLUMN IF NOT EXISTS `delivery_region_name` varchar(255) DEFAULT NULL AFTER `delivery_area_name`,
  ADD COLUMN IF NOT EXISTS `delivery_city_name` varchar(255) DEFAULT NULL AFTER `delivery_region_name`,
  ADD COLUMN IF NOT EXISTS `delivery_city_ref` varchar(64) DEFAULT NULL AFTER `delivery_city_name`,
  ADD COLUMN IF NOT EXISTS `delivery_settlement_ref` varchar(64) DEFAULT NULL AFTER `delivery_city_ref`,
  ADD COLUMN IF NOT EXISTS `delivery_branch_ref` varchar(64) DEFAULT NULL AFTER `delivery_settlement_ref`,
  ADD COLUMN IF NOT EXISTS `delivery_branch_number` int(11) DEFAULT NULL AFTER `delivery_branch_ref`,
  ADD COLUMN IF NOT EXISTS `delivery_address` varchar(255) DEFAULT NULL AFTER `delivery_branch_number`,
  ADD COLUMN IF NOT EXISTS `delivery_payer` varchar(64) DEFAULT NULL AFTER `delivery_address`,
  ADD COLUMN IF NOT EXISTS `delivery_has_postpay` tinyint(1) DEFAULT NULL AFTER `delivery_payer`,
  ADD COLUMN IF NOT EXISTS `delivery_postpay_sum` decimal(10,2) DEFAULT 0.00 AFTER `delivery_has_postpay`,
  ADD COLUMN IF NOT EXISTS `delivery_payment_method` varchar(64) DEFAULT NULL AFTER `delivery_postpay_sum`,
  ADD COLUMN IF NOT EXISTS `delivery_cargo_type` varchar(64) DEFAULT NULL AFTER `delivery_payment_method`;

CREATE INDEX IF NOT EXISTS `idx_orders_order_time` ON `orders` (`order_time`);
CREATE INDEX IF NOT EXISTS `idx_orders_update_at` ON `orders` (`update_at`);
CREATE INDEX IF NOT EXISTS `idx_orders_payment_date` ON `orders` (`payment_date`);
CREATE INDEX IF NOT EXISTS `idx_orders_status_id` ON `orders` (`status_id`);
CREATE INDEX IF NOT EXISTS `idx_orders_manager_id` ON `orders` (`manager_id`);
CREATE INDEX IF NOT EXISTS `idx_orders_site_id` ON `orders` (`site_id`);
CREATE INDEX IF NOT EXISTS `idx_orders_delivery_city_name` ON `orders` (`delivery_city_name`);

