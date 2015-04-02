import MySQLdb
from pyhs.sockets import ReadSocket

def insert(para):
        i = 11
        while (i<100000):
                data = (i, 'Jane', 'Doe')
                cursor = conn.cursor()
                cursor.execute('INSERT INTO album (album_id, title, artist) VALUES (%s, %s, %s)', data)
                i = i + 1
                conn.commit()
                print i

conn = MySQLdb.connect (host = "localhost",
                            user = "root",
                            passwd = "",
                            db = "myalbum")
insert(conn)
