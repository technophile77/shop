-- Migration 002: Add delivery_fee column to orders table
-- Run after 001_initial_schema.sql
SET NAMES utf8mb4;
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS delivery_fee DECIMAL(8,2) NULL AFTER delivery_address;
