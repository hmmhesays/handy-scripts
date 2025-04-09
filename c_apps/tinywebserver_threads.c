#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <fcntl.h>
#include <pthread.h>
#include <arpa/inet.h>
#include <netinet/in.h>
#include <sys/stat.h>
#include <sys/file.h>
#include <sys/socket.h>
#include <time.h>


#define BUF_SIZE 4096

typedef struct {
    int client_fd;
    char docroot[1024];
} client_args;

char *timestamp() {
    static char buf[64];
    time_t now = time(NULL);
    struct tm *tm_info = localtime(&now);
    strftime(buf, sizeof(buf), "%Y-%m-%d %H:%M:%S", tm_info);
    return buf;
}
void join_path(char *dest, const char *docroot, const char *url_path, size_t maxlen) {
    if (docroot[strlen(docroot) - 1] == '/')
        snprintf(dest, maxlen, "%s%s", docroot, url_path[0] == '/' ? url_path + 1 : url_path);
    else
        snprintf(dest, maxlen, "%s/%s", docroot, url_path[0] == '/' ? url_path + 1 : url_path);
}

const char *get_mime_type(const char *path) {
    if (strstr(path, ".html")) return "text/html";
    if (strstr(path, ".css")) return "text/css";
    if (strstr(path, ".js")) return "application/javascript";
    if (strstr(path, ".jpg")) return "image/jpeg";
    if (strstr(path, ".jpeg")) return "image/jpeg";
    if (strstr(path, ".png")) return "image/png";
    if (strstr(path, ".gif")) return "image/gif";
    if (strstr(path, ".txt")) return "text/plain";
    return "application/octet-stream";
}

void serve_file1(int client_fd, const char *filepath) {
    int fd = open(filepath, O_RDONLY);
    if (fd < 0) {
        printf("[%s] [Response] 404 %s\n", timestamp(), filepath);
        fflush(stdout);
        dprintf(client_fd,
            "HTTP/1.0 404 Not Found\r\nContent-Type: text/plain\r\n\r\nFile not found.\n");
        return;
    }

    struct stat st;
    fstat(fd, &st);
    const char *mime = get_mime_type(filepath);

    printf("[%s] [Response] 200 %s (%s)\n", timestamp(), filepath, mime);
    fflush(stdout);

    dprintf(client_fd,
        "HTTP/1.0 200 OK\r\nContent-Length: %ld\r\nContent-Type: %s\r\n\r\n",
        st.st_size, mime);

    char buf[BUF_SIZE];
    ssize_t n;
    while ((n = read(fd, buf, BUF_SIZE)) > 0) {
        write(client_fd, buf, n);
    }

    close(fd);
}

void serve_file(int client_fd, const char *fullpath) {
    int fd = open(fullpath, O_RDONLY);
    if (fd == -1) {
        dprintf(client_fd, "HTTP/1.0 404 Not Found\r\nContent-Type: text/plain\r\n\r\nFile not found.\n");
        return;
    }

    // Apply a read lock (shared lock) to the file
    if (flock(fd, LOCK_SH) == -1) {
        dprintf(client_fd, "HTTP/1.0 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nFailed to lock file.\n");
        close(fd);
        return;
    }

    // Get the file size
    off_t file_size = lseek(fd, 0, SEEK_END);
    lseek(fd, 0, SEEK_SET);

    // Send the HTTP response header
    dprintf(client_fd, "HTTP/1.0 200 OK\r\n");
    dprintf(client_fd, "Content-Type: text/html\r\n");
    dprintf(client_fd, "Content-Length: %ld\r\n", file_size);
    dprintf(client_fd, "\r\n");

    // Read the file and send its contents
    char buffer[BUF_SIZE];
    ssize_t bytes_read;
    while ((bytes_read = read(fd, buffer, sizeof(buffer))) > 0) {
        write(client_fd, buffer, bytes_read);
    }

    // Unlock the file after reading
    flock(fd, LOCK_UN);

    // Close the file descriptor
    close(fd);
}

void *handle_client_thread(void *arg) {
    client_args *cargs = (client_args *)arg;
    int client_fd = cargs->client_fd;

    // Copy docroot to a new memory location to preserve it
    const char *docroot = strdup(cargs->docroot);

    // Free cargs structure after copying docroot
    free(cargs);

    if (docroot == NULL) {
        fprintf(stderr, "[%s] [Error] Failed to copy docroot.\n", timestamp());
        close(client_fd);
        return NULL;
    }

    char buf[BUF_SIZE];
    ssize_t n = read(client_fd, buf, BUF_SIZE - 1);
    if (n <= 0) {
        free((void *)docroot); // Free docroot before returning
        close(client_fd);
        return NULL;
    }
    buf[n] = '\0';

    char method[8], path[1024];
    if (sscanf(buf, "%7s %1023s", method, path) != 2) {
        fprintf(stderr, "[%s] [Malformed Request]\n", timestamp());
        fflush(stderr);
        free((void *)docroot); // Free docroot before returning
        close(client_fd);
        return NULL;
    }

    printf("[%s] [Request] %s %s\n", timestamp(), method, path);
    fflush(stdout);

    if (strcmp(method, "GET") != 0) {
        dprintf(client_fd,
            "HTTP/1.0 405 Method Not Allowed\r\nContent-Type: text/plain\r\n\r\nOnly GET supported.\n");
        free((void *)docroot); // Free docroot before returning
        close(client_fd);
        return NULL;
    }

    if (strstr(path, "..")) {
        dprintf(client_fd,
            "HTTP/1.0 403 Forbidden\r\nContent-Type: text/plain\r\n\r\nAccess denied.\n");
        free((void *)docroot); // Free docroot before returning
        close(client_fd);
        return NULL;
    }

    if (strcmp(path, "/") == 0) strcpy(path, "/index.html");

    char fullpath[2048];
    join_path(fullpath, docroot, path, sizeof(fullpath));
    printf("[%s] [DEBUG] Full path resolved: %s\n", timestamp(), fullpath);
    fflush(stdout);

    serve_file(client_fd, fullpath);

    free((void *)docroot); // Free docroot after use
    close(client_fd);
    return NULL;
}

int main(int argc, char *argv[]) {
    setvbuf(stdout, NULL, _IONBF, 0);  // Disable stdout buffering

    if (argc != 4) {
        fprintf(stderr, "Usage: %s <host> <port> <document_root>\n", argv[0]);
        exit(1);
    }

    const char *host = argv[1];
    int port = atoi(argv[2]);
    const char *docroot = argv[3];

    int server_fd = socket(AF_INET, SOCK_STREAM, 0);
    if (server_fd < 0) perror("socket"), exit(1);

    int opt = 1;
    setsockopt(server_fd, SOL_SOCKET, SO_REUSEADDR, &opt, sizeof(opt));

    struct sockaddr_in addr = {0};
    addr.sin_family = AF_INET;
    addr.sin_port = htons(port);
    inet_pton(AF_INET, host, &addr.sin_addr);

    if (bind(server_fd, (struct sockaddr *)&addr, sizeof(addr)) < 0)
        perror("bind"), exit(1);
    if (listen(server_fd, 10) < 0)
        perror("listen"), exit(1);

    printf("[%s] Server listening on %s:%d\n", timestamp(), host, port);

    while (1) {
        int client_fd = accept(server_fd, NULL, NULL);
        if (client_fd < 0) continue;

        client_args *cargs = malloc(sizeof(client_args));
        cargs->client_fd = client_fd;
        strncpy(cargs->docroot, docroot, sizeof(cargs->docroot) - 1);
        cargs->docroot[sizeof(cargs->docroot) - 1] = '\0';

        pthread_t tid;
        if (pthread_create(&tid, NULL, handle_client_thread, cargs) != 0) {
            perror("pthread_create");
            close(client_fd);
            free(cargs);
            continue;
        }

        pthread_detach(tid);  // Auto cleanup
    }

    return 0;
}
