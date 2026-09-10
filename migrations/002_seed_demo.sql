-- Optional demo articles — same set as the Claude Design prototype. Run only for a demo/test setup.
INSERT INTO products (name, category, price_cents, cost_cents, stock, stock_min, sort_order) VALUES
  ('Bratwurst im Brötchen','Speisen',350,120,120,20,1),
  ('Currywurst mit Pommes','Speisen',650,240, 80,15,2),
  ('Pommes rot/weiß','Speisen',300, 80,150,25,3),
  ('Schnitzelbrötchen','Speisen',500,210, 60,10,4),
  ('Gulaschsuppe','Speisen',450,160, 50,10,5),
  ('Käsespätzle','Speisen',700,250, 40, 8,6),
  ('Bier 0,5 l','Getränke',400,110,240,40,7),
  ('Radler 0,5 l','Getränke',400,115, 90,20,8),
  ('Weinschorle','Getränke',450,140, 70,15,9),
  ('Cola 0,33 l','Getränke',250, 70,180,30,10),
  ('Wasser 0,5 l','Getränke',200, 40,200,30,11),
  ('Kaffee','Getränke',250, 35,100,20,12),
  ('Kuchen (Stück)','Süßes',300, 90, 45,10,13),
  ('Waffel','Süßes',350,100, 55,12,14),
  ('Eis am Stiel','Süßes',200, 60, 90,20,15);
