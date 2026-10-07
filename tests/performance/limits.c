/* Disposable fixture launcher. Linux process limits supplement unenforced
 * nested/rootless container cgroup flags. This file is not shipped in Core. */
#define _GNU_SOURCE
#include <errno.h>
#include <dirent.h>
#include <ctype.h>
#include <limits.h>
#include <sched.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/resource.h>
#include <unistd.h>

/* Read inside the owned PID namespace: nested Podman cannot reliably map exec
 * workers to host PIDs. Include the bounded probe itself in the RSS total. */
static int rss_probe(void) {
    DIR *directory = opendir("/proc");
    if (!directory) { perror("RSS opendir /proc"); return 125; }
    unsigned long long total = 0;
    unsigned count = 0;
    struct dirent *entry;
    while ((entry = readdir(directory))) {
        if (!isdigit((unsigned char)entry->d_name[0])) continue;
        char path[512];
        snprintf(path, sizeof(path), "/proc/%s/status", entry->d_name);
        FILE *status = fopen(path, "r");
        if (!status) {
            if (errno == ENOENT) continue;
            fprintf(stderr, "RSS open %s: %s\n", path, strerror(errno));
            closedir(directory);
            return 125;
        }
        char line[512], state = 0;
        unsigned long long kib = 0;
        int found = 0, kernel_thread = 0;
        while (fgets(line, sizeof(line), status)) {
            if (!strncmp(line, "State:", 6)) sscanf(line, "State: %c", &state);
            if (!strncmp(line, "Kthread:", 8)) sscanf(line, "Kthread: %d", &kernel_thread);
            if (!strncmp(line, "VmRSS:", 6)) {
                char unit[8];
                if (sscanf(line, "VmRSS: %llu %7s", &kib, unit) != 2 || strcmp(unit, "kB")) {
                    fprintf(stderr, "RSS malformed %s: %s", path, line);
                    fclose(status);
                    closedir(directory);
                    return 125;
                }
                found = 1;
            }
        }
        int failed = ferror(status);
        fclose(status);
        if (failed || (!found && state != 'Z' && state != 'X' && kernel_thread != 1)) {
            /* A task may disappear while its already-open status is read. */
            if (access(path, F_OK) == -1 && errno == ENOENT) continue;
            fprintf(stderr, "RSS incomplete %s: state=%c found=%d read_error=%d kernel_thread=%d\n", path, state ? state : '?', found, failed, kernel_thread);
            closedir(directory);
            return 125;
        }
        total += found ? kib * 1024 : 0;
        ++count;
    }
    closedir(directory);
    if (!count) { fprintf(stderr, "RSS no processes observed\n"); return 125; }
    printf("{\"rss_bytes\":%llu,\"processes\":%u}\n", total, count);
    return 0;
}

int main(int argc, char **argv) {
    if (argc < 4) {
        fprintf(stderr, "usage: limits CPU_LIST ADDRESS_SPACE_MIB PROGRAM [ARGS...]\n");
        return 125;
    }
    cpu_set_t cpus;
    CPU_ZERO(&cpus);
    char *list = strdup(argv[1]);
    if (!list) return 125;
    unsigned count = 0;
    for (char *token = strtok(list, ","); token; token = strtok(NULL, ",")) {
        char *end = NULL;
        long cpu = strtol(token, &end, 10);
        if (!end || *end || cpu < 0 || cpu >= CPU_SETSIZE || ++count > 6) {
            fprintf(stderr, "invalid CPU list\n");
            return 125;
        }
        CPU_SET(cpu, &cpus);
    }
    free(list);
    char *end = NULL;
    unsigned long mib = strtoul(argv[2], &end, 10);
    if (!count || !end || *end || mib < 64 || mib > 6144) {
        fprintf(stderr, "invalid address-space limit\n");
        return 125;
    }
    struct rlimit memory = {(rlim_t)mib * 1024 * 1024, (rlim_t)mib * 1024 * 1024};
    struct rlimit core = {0, 0};
    if (sched_setaffinity(0, sizeof(cpus), &cpus) || setrlimit(RLIMIT_AS, &memory) || setrlimit(RLIMIT_CORE, &core)) {
        perror("resource enforcement");
        return 125;
    }
    if (!strcmp(argv[3], "--rss-probe")) return rss_probe();
    execvp(argv[3], argv + 3);
    perror("bounded exec");
    return 125;
}
