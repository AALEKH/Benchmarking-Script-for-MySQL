import time
from pyhs import Manager
import sys
import MySQLdb
from pyhs.sockets import ReadSocket

def insert(para):
        i = 0
        while (i<10):
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
#insert(conn)
hs = Manager()

#hs = ReadSocket([('inet', '127.0.0.1', 9998)])

start = time.time()
i = 1
j= 0
while i < 10001:
        data = hs.get('myalbum', 'album', ['album_id', 'title', 'artist'], '%s' % str(i))                                                                                                                   1,1           Top
        print data
        i=i+1
        end = time.time()
        j = j + 1

print "Using HandlerSocket, below is a report :"
print "Seconds elapsed: %s", str(end - start)
print "Rows retrieved: %s", str(j)
