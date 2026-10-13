import hashlib,importlib.util,io,tempfile,unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('receiver',str(Path(__file__).resolve().parents[1] / 'receive-routing-archive.py'))
r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
class ReceiverTests(unittest.TestCase):
 def test_command_cannot_execute_shell(self):
  for s in ['', 'sh', 'upload-routing '+('a'*64)+' 1;id', 'upload-routing ../test 1']:
   with self.assertRaises(ValueError):r.parse_request(s)
 def test_sizes_are_bounded(self):
  for size in ['0','-1',str(r.MAX_SIZE+1)]:
   with self.assertRaises(ValueError):r.parse_request('upload-routing '+('a'*64)+' '+size)
 def test_valid_request(self):
  self.assertEqual(r.parse_request('upload-routing '+('a'*64)+' 42'),('a'*64,42))
 def test_valid_transfer(self):
  with tempfile.TemporaryDirectory() as d:
   p=Path(d);data=b'fixture graph';h=hashlib.sha256(data).hexdigest()
   result=r.receive(io.BytesIO(data),p,h,len(data));self.assertEqual(result['sha256'],h)
   self.assertEqual((p/result['filename']).read_bytes(),data)
   self.assertEqual((p/result['filename']).stat().st_mode&0o777,0o600)
 def test_bad_upload_preserves_previous_graph(self):
  for data,size,checksum in [(b'short',20,'a'*64),(b'long',2,'a'*64),(b'bad',3,'a'*64)]:
   with self.subTest(data=data),tempfile.TemporaryDirectory() as d:
    p=Path(d);target=p/'indonesia-osrm-20261012.tar.gz';target.write_bytes(b'previous')
    with self.assertRaises(ValueError):r.receive(io.BytesIO(data),p,checksum,size)
    self.assertEqual(target.read_bytes(),b'previous');self.assertFalse(list(p.glob('*.part')))
 def test_symlink_cannot_be_overwritten(self):
  with tempfile.TemporaryDirectory() as d:
   p=Path(d);victim=p/'victim';victim.write_bytes(b'preserve')
   (p/'indonesia-osrm-20261012.tar.gz.part').symlink_to(victim)
   with self.assertRaises(OSError):r.receive(io.BytesIO(b'x'),p,hashlib.sha256(b'x').hexdigest(),1)
   self.assertEqual(victim.read_bytes(),b'preserve')
unittest.main()
