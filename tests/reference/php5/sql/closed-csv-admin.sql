SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT anyfile AS file, vouchers.id, vouchers.voucher_id, vouchers.type, vouchers.orga, vouchers.member, vouchers.member_id, vouchers.contra_account, vouchers.name, vouchers.street, vouchers.plz, vouchers.city, vouchers.amount, vouchers.account, vouchers.comment, vouchers.committed, vouchers.receipt_received, vouchers.deleted, vouchers.date, vouchers.ack1, vouchers.ack2, vouchers.person_type, vouchers.save_date, vouchers.save_user, vouchers.commentgf, vouchers.refund, lo.name AS lo_name, type.name AS type_name FROM vouchers LEFT JOIN lo ON vouchers.orga = lo.id LEFT JOIN type ON vouchers.type = type.id LEFT JOIN (SELECT voucher_id,1 as anyfile FROM vouchers F WHERE F.file != 0 AND F.ack1 IS NOT NULL AND F.ack2 IS NOT NULL GROUP BY voucher_id) F ON F.voucher_id = vouchers.voucher_id WHERE NOT deleted AND ack1 IS NOT NULL AND ack2 IS NOT NULL   ORDER BY date ASC, voucher_id DESC, vouchers.id ASC
-- next
SELECT name FROM type WHERE id = 8
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 20
-- next
SELECT name FROM lo WHERE id = 6
-- next
SELECT name FROM type WHERE id = 8
-- next
SELECT name FROM lo WHERE id = 6
-- next
SELECT name FROM type WHERE id = 20
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 21
-- next
SELECT name FROM lo WHERE id = 10
-- next
SELECT name FROM type WHERE id = 11
-- next
SELECT name FROM lo WHERE id = 6
-- next
SELECT name FROM type WHERE id = 8
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 28
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 20
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 21
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 8
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 11
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 1029
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 29
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 30
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 1
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 20
-- next
SELECT name FROM lo WHERE id = 4
