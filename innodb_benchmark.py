import sys
import MySQLdb
import memcache
import timeit

def insert(para):
        i = 0
        while (i<10):
                data = (i, 'Jane', 'Doe')
                cursor = conn.cursor()
                cursor.execute('INSERT INTO album (album_id, title, artist) VALUES (%s, %s, %s)', data)
                i = i + 1
                conn.commit()
                print i

def memcache_get(see):
        popularfilms = memc.get('top5films')
        print "Loaded data from memcached"
        for row in popularfilms:
          print "%s, %s" % (row[0], row[1])
memc = memcache.Client(['127.0.0.1:11211'], debug=1);
try:
    conn = MySQLdb.connect (host = "localhost",
                            user = "root",
                            passwd = "",
                            db = "myalbum")
except MySQLdb.Error, e:
     print "Error %d: %s" % (e.args[0], e.args[1])
     sys.exit (1)
popularfilms = memc.get('a1')
if not popularfilms:
    cursor = conn.cursor()
    ##insert(conn)
    j = 0
    start_time = timeit.default_timer()
    while (j<10000):
        cursor.execute('select album_id from album where album_id = {0}'.format(j))
        rows = cursor.fetchall()
        memc.set('a'+str(j),rows,216000)
        j =j + 1
        print j
    elapsed = timeit.default_timer() - start_time
    print "Updated memcached with MySQL data:" + str(elapsed)
else:
    cursor = conn.cursor()
    print "Loaded data from memcached"
    k = 0
    t = 0
    start_time = timeit.default_timer()
    while (t<10000):
        cursor.execute('select album_id from album where album_id = {0}'.format(t))
        rows = cursor.fetchall()
        t = t + 1
        print t
    elapsed = timeit.default_timer() - start_time
    print "All sql complete:" + str(elapsed) + " sec"
    start_time = timeit.default_timer()
    while (k < 10000):
        popularfilms = memc.get('a'+str(k))
        k = k + 1
        #print popularfilms
    elapsed = timeit.default_timer() - start_time
    print "All memcached complete:" + str(elapsed) + " sec"

