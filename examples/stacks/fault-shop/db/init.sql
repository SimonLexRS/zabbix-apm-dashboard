CREATE TABLE products (
	id integer PRIMARY KEY,
	name text NOT NULL,
	category text NOT NULL,
	price numeric(8, 2) NOT NULL,
	stock integer NOT NULL
);

CREATE TABLE orders (
	id bigserial PRIMARY KEY,
	created timestamptz NOT NULL DEFAULT now(),
	email text NOT NULL,
	items integer NOT NULL,
	total numeric(10, 2) NOT NULL
);

INSERT INTO products (id, name, category, price, stock) VALUES
	(1, 'Mechanical keyboard', 'Desk', 89.00, 120),
	(2, 'Wireless mouse', 'Desk', 34.50, 300),
	(3, 'USB-C dock', 'Desk', 129.00, 80),
	(4, '27" monitor', 'Desk', 319.00, 40),
	(5, 'Laptop stand', 'Desk', 42.00, 150),
	(6, 'Noise-cancelling headphones', 'Audio', 199.00, 60),
	(7, 'Bluetooth speaker', 'Audio', 59.90, 200),
	(8, 'USB microphone', 'Audio', 74.00, 90),
	(9, 'Webcam 1080p', 'Video', 49.00, 180),
	(10, 'Ring light', 'Video', 27.50, 220),
	(11, 'Cat 6 cable, 5 m', 'Network', 8.90, 900),
	(12, 'Wi-Fi 7 router', 'Network', 149.00, 70),
	(13, 'Mystery box', 'Offers', 19.90, 500),
	(14, '8-port switch', 'Network', 39.00, 130),
	(15, 'Portable SSD 1 TB', 'Storage', 99.00, 110),
	(16, 'microSD card 256 GB', 'Storage', 24.90, 400);
