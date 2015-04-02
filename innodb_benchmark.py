import sys
import MySQLdb
import memcache
import timeit

##INSERT INTO employees (emp_no, first_name, last_name, hire_date) "
##  "VALUES (%s, %s, %s, %s)"

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
                            user = "add user",
                            passwd = "add password",
                            db = "myalbum")
except MySQLdb.Error, e:
     print "Error %d: %s" % (e.args[0], e.args[1])
     sys.exit (1)
popularfilms = memc.get('1')
if not popularfilms:
    cursor = conn.cursor()
    ##insert(conn)
    j = 0
    start_time = timeit.default_timer()
    while (j<10):
        cursor.execute('select album_id from album where album_id = %s', str(j))
        rows = cursor.fetchall()
        memc.set(str(j),rows,3600)
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
    while (t<10):
        cursor.execute('select album_id from album where album_id = %s', str(t))
        rows = cursor.fetchall()
        t = t + 1
        #print rows
    elapsed = timeit.default_timer() - start_time
    print "All sql complete:" + str(elapsed) + " sec"
    start_time = timeit.default_timer()
    while (k < 10):
        popularfilms = memc.get(str(k))
        k = k + 1
        #print popularfilms
    elapsed = timeit.default_timer() - start_time
    print "All memcached complete:" + str(elapsed) + " sec"
   
