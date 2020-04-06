#!/bin/bash
#Проект может быть просто создан, склонирован с github
#$1 - название проекта
#$2 - название проекта для копии, если нет, то указать 0
#$3 - тип clone, local
#$4 - путь к репозиторию для clone

if [[ $# > 0 ]]
then
    DIR=$(dirname $(readlink -e $0))
    ProjDIR=$DIR"/projects/"$1
    #Создать папку
    cd
    mkdir -p $ProjDIR
    
    recipient=$2
    if [[ $2 == '' ]] || [[ $2 == '0' ]]; then recipient='_sample'; fi
    if [[ $3 != 'clone' ]]
    then
        if [ -d $DIR"/projects/"$recipient ]
        then
            cp -a $DIR"/projects/"$recipient"/." $ProjDIR
        else
            echo "Проект для копирования не найден"
            exit
        fi
    fi
    
    if [[ $3 != '' ]]
    then
        if [[ $3 == 'clone' ]]
        then
            if [[ $4 != '' ]]
            then
                echo "Клон репозитория"
                git clone $4 $ProjDIR
            else
                echo "Укажите адрес репозитория"
                exit
            fi
        fi
        if [[ $3 == 'local' ]]
        then
            echo "Локальный репозиторий"
            cd $ProjDIR
            git init
            cd
        fi
    fi
    
    if [[ $3 == 'local' ]]
    then
        cd $ProjDIR
        git add .
        git commit -m "Initial Commit"
        cd
    fi
else
    echo "Укажите имя проекта"
    exit
fi